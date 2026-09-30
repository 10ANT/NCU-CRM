<?php

declare(strict_types=1);

/*
 * Tests for the MySQL compatibility port. Not upstream code.
 *
 * These cover the pure SQL translation the port performs, with no database and no application boot,
 * so they run in milliseconds and can be used as a fast guard while working on the port.
 *
 * The behavioural half of the proof, that the translated SQL actually does the same thing MySQL, lives
 * in tests/Tenant/Database/MySqlPortBehaviourTest.php.
 */

use App\Database\Schema\MySqlEnhancedSchemaMacros;
use App\Database\Schema\PartialIndexCondition;
use App\Database\Schema\PartialIndexSqlTranslator;
use App\Database\Schema\PlPgSqlTriggerTranslator;
use App\Database\Schema\PostgresCastTranslator;

describe('PostgreSQL cast translation', function () {
    it('rewrites a cast on a plain column', function () {
        expect(PostgresCastTranslator::translate('select user_id::VARCHAR from events'))
            ->toBe('select CAST(user_id AS CHAR) from events');
    });

    it('maps uuid to a 36 character string, which is how Laravel stores uuids on MySQL', function () {
        expect(PostgresCastTranslator::translate('select NULL::uuid'))
            ->toBe('select CAST(NULL AS CHAR(36))');
    });

    it('rewrites a cast on a quoted literal', function () {
        // From GenerateStudentAlertsView, which embeds the alert id as a literal.
        expect(PostgresCastTranslator::translate("select '11111111-1111-1111-1111-111111111111'::uuid as id"))
            ->toBe("select CAST('11111111-1111-1111-1111-111111111111' AS CHAR(36)) as id");
    });

    it('casts an entire function call, not just its closing bracket', function () {
        expect(PostgresCastTranslator::translate('select coalesce(a, b)::text'))
            ->toBe('select CAST(coalesce(a, b) AS CHAR)');
    });

    it('handles a two word type name', function () {
        // GenerateStudentAlertsView declares NULL::character varying(255).
        expect(PostgresCastTranslator::translate('select NULL::character varying(255) as sisid'))
            ->toBe('select CAST(NULL AS CHAR) as sisid');
    });

    it('maps jsonb to json', function () {
        expect(PostgresCastTranslator::translate('select subject::jsonb'))
            ->toBe('select CAST(subject AS JSON)');
    });

    it('leaves a double colon inside a string literal completely alone', function () {
        // This is the case that would corrupt stored data if the translation were a blind replace.
        $sql = "insert into logs (message) values ('namespace::method failed')";

        expect(PostgresCastTranslator::handles($sql))->toBeTrue()
            ->and(PostgresCastTranslator::translate($sql))->toBe($sql);
    });

    it('does not mistake an escaped quote for the end of a literal', function () {
        $sql = "select 'it''s a::b' as note, id::uuid from t";

        expect(PostgresCastTranslator::translate($sql))
            ->toBe("select 'it''s a::b' as note, CAST(id AS CHAR(36)) from t");
    });

    it('rewrites several casts in one statement', function () {
        expect(PostgresCastTranslator::translate('select a::text, b::int from t'))
            ->toBe('select CAST(a AS CHAR), CAST(b AS SIGNED) from t');
    });

    it('refuses an unmapped type rather than leaving PostgreSQL syntax in place', function () {
        // Failing loudly matters here: a cast left untranslated is either a syntax error or, worse,
        // silently different behaviour.
        expect(fn () => PostgresCastTranslator::translate('select a::tsvector'))
            ->toThrow(RuntimeException::class);
    });

    it('reports statements with no cast as not needing translation', function () {
        expect(PostgresCastTranslator::handles('select 1'))->toBeFalse();
    });
});

describe('partial index condition normalisation', function () {
    it('rewrites a quoted boolean true to MySQL integer truth', function () {
        // PostgreSQL coerces the literal 'true' to boolean; MySQL coerces the other way and reads
        // 'true' as 0, which selects exactly the rows PostgreSQL would exclude.
        expect(PartialIndexCondition::normalise("`is_default` = 'true'"))
            ->toBe('`is_default` = 1');
    });

    it('rewrites a quoted boolean false', function () {
        expect(PartialIndexCondition::normalise("`is_default` = 'false'"))
            ->toBe('`is_default` = 0');
    });

    it('handles an inequality', function () {
        expect(PartialIndexCondition::normalise("`flag` <> 'true'"))
            ->toBe('`flag` <> 1');
    });

    it('leaves an ordinary string that merely starts with true untouched', function () {
        expect(PartialIndexCondition::normalise("`name` = 'true story'"))
            ->toBe("`name` = 'true story'");
    });

    it('leaves a condition with no boolean literal untouched', function () {
        expect(PartialIndexCondition::normalise('`deleted_at` is null'))
            ->toBe('`deleted_at` is null');
    });
});

describe('raw partial index translation', function () {
    it('turns a PostgreSQL partial unique index into a MySQL functional unique index', function () {
        // Verbatim from
        // app-modules/interaction/database/migrations/2025_03_17_170737_update_unique_key_in_interaction_outcomes_table.php
        $sql = 'CREATE UNIQUE INDEX interaction_outcomes_is_default_unique
                ON interaction_outcomes (is_default)
                WHERE is_default = true AND deleted_at IS NULL;';

        expect(PartialIndexSqlTranslator::handles($sql))->toBeTrue()
            ->and(PartialIndexSqlTranslator::translate($sql))->toBe([
                'create unique index `interaction_outcomes_is_default_unique` on `interaction_outcomes` '
                . '((case when is_default = true AND deleted_at IS NULL then `is_default` end))',
            ]);
    });

    it('keeps the index non unique when the source was not unique', function () {
        $sql = 'CREATE INDEX idx ON t (a) WHERE b IS NULL';

        expect(PartialIndexSqlTranslator::translate($sql))
            ->toBe(['create index `idx` on `t` ((case when b IS NULL then `a` end))']);
    });

    it('produces one key part per column so a multi column partial index still works', function () {
        $sql = 'CREATE UNIQUE INDEX idx ON t (a, b) WHERE deleted_at IS NULL';

        expect(PartialIndexSqlTranslator::translate($sql)[0])
            ->toContain('(case when deleted_at IS NULL then `a` end)')
            ->toContain('(case when deleted_at IS NULL then `b` end)');
    });

    it('ignores an index with no WHERE clause, which MySQL already accepts', function () {
        expect(PartialIndexSqlTranslator::handles('CREATE INDEX idx ON t (a)'))->toBeFalse();
    });
});

describe('trigger action parsing', function () {
    it('separates the function name from its arguments', function () {
        expect(MySqlEnhancedSchemaMacros::parseTriggerAction(
            "prevent_modification_of_system_protected_rows('sort', 'updated_at')"
        ))->toBe(['prevent_modification_of_system_protected_rows', ['sort', 'updated_at']]);
    });

    it('handles a function called with no arguments', function () {
        expect(MySqlEnhancedSchemaMacros::parseTriggerAction('some_function()'))
            ->toBe(['some_function', []]);
    });

    it('rejects an action it cannot parse instead of guessing', function () {
        expect(fn () => MySqlEnhancedSchemaMacros::parseTriggerAction('not a function call'))
            ->toThrow(RuntimeException::class);
    });
});

describe('trigger event expansion', function () {
    it('splits a multi event PostgreSQL trigger into one MySQL trigger per event', function () {
        // MySQL allows a single event per trigger, so BEFORE UPDATE OR DELETE has to become two.
        expect(PlPgSqlTriggerTranslator::events('BEFORE UPDATE OR DELETE'))
            ->toBe(['BEFORE UPDATE', 'BEFORE DELETE']);
    });

    it('preserves AFTER timing', function () {
        expect(PlPgSqlTriggerTranslator::events('AFTER INSERT'))->toBe(['AFTER INSERT']);
    });

    it('keeps all three events when all are named', function () {
        expect(PlPgSqlTriggerTranslator::events('BEFORE INSERT OR UPDATE OR DELETE'))
            ->toBe(['BEFORE INSERT', 'BEFORE UPDATE', 'BEFORE DELETE']);
    });

    it('raises when no event can be determined', function () {
        expect(fn () => PlPgSqlTriggerTranslator::events('BEFORE SOMETHING'))
            ->toThrow(RuntimeException::class);
    });
});
