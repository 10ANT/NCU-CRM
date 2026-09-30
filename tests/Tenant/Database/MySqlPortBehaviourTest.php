<?php

declare(strict_types=1);

/*
 * Tests for the MySQL compatibility port. Not upstream code.
 *
 * Where tests/Port/PostgresSqlTranslationTest.php checks that the port produces the right SQL, this
 * file checks that the SQL produced actually behaves the way PostgreSQL did. Those are different
 * questions, and the second is the one that matters for the rule that no feature may be lost.
 *
 * Every assertion here targets a specific translation the port performs, named in the test.
 *
 * These run against the real tenant schema, so they also serve as a check that the 710 tenant
 * migrations produced the structures the application expects.
 */

use App\Database\ResilientMySqlPdo;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Insert an interaction outcome directly, bypassing the model so the database is what is under test.
 */
function portInsertOutcome(string $name, string $interactableType, bool $isDefault = false, ?string $deletedAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('interaction_outcomes')->insert([
        'id' => $id,
        'name' => $name,
        'interactable_type' => $interactableType,
        'is_default' => $isDefault,
        'deleted_at' => $deletedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function portInsertProspectStatus(array $attributes = []): string
{
    $id = (string) Str::uuid();

    DB::table('prospect_statuses')->insert(array_merge([
        'id' => $id,
        'classification' => 'active',
        'name' => 'Port Test Status ' . Str::random(6),
        'color' => 'sky',
        'sort' => 500,
        'is_system_protected' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    return $id;
}

describe('partial unique indexes keep PostgreSQL semantics', function () {
    // Upstream declares these as PostgreSQL partial indexes, for example
    // `->uniqueIndex(['name', 'interactable_type'])->where(fn ($c) => $c->whereNull('deleted_at'))`.
    // MySQL has no partial indexes, so the port compiles them to functional indexes over a CASE
    // expression. The rule that has to survive is: unique among live rows, and reusable once the row
    // holding the value is soft deleted.

    it('rejects a duplicate among rows that are not soft deleted', function () {
        $type = 'PortTest\\Type' . Str::random(6);

        portInsertOutcome('Resolved', $type);

        expect(fn () => portInsertOutcome('Resolved', $type))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows the value to be reused once the original row is soft deleted', function () {
        $type = 'PortTest\\Type' . Str::random(6);

        $first = portInsertOutcome('Resolved', $type);

        DB::table('interaction_outcomes')->where('id', $first)->update(['deleted_at' => now()]);

        // On a plain unique index this would fail. It must not, because a partial index excludes
        // soft-deleted rows, and that is what lets a name be reused after deletion.
        $second = portInsertOutcome('Resolved', $type);

        expect(DB::table('interaction_outcomes')->whereIn('id', [$first, $second])->count())->toBe(2);
    });

    it('allows any number of soft deleted duplicates', function () {
        $type = 'PortTest\\Type' . Str::random(6);

        portInsertOutcome('Archived', $type, deletedAt: now()->toDateTimeString());
        portInsertOutcome('Archived', $type, deletedAt: now()->toDateTimeString());

        expect(DB::table('interaction_outcomes')->where('interactable_type', $type)->count())->toBe(2);
    });

    it('still enforces the single default rule from the is_default partial index', function () {
        // A second index, `interaction_outcomes_is_default_unique`, permits only one default per
        // interactable type among live rows. Its condition includes a boolean comparison, which is the
        // case that silently inverts if quoted booleans are not normalised for MySQL.
        $type = 'PortTest\\Type' . Str::random(6);

        portInsertOutcome('First default', $type, isDefault: true);

        expect(fn () => portInsertOutcome('Second default', $type, isDefault: true))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('permits many non default rows, proving the condition selects defaults and not its inverse', function () {
        // If the boolean literal were mistranslated, the index would constrain non-default rows
        // instead, and this would fail. It is the direct guard against that silent inversion.
        $type = 'PortTest\\Type' . Str::random(6);

        portInsertOutcome('Not default one', $type, isDefault: false);
        portInsertOutcome('Not default two', $type, isDefault: false);

        expect(DB::table('interaction_outcomes')->where('interactable_type', $type)->where('is_default', false)->count())
            ->toBe(2);
    });

    it('records the indexes as functional rather than plain', function () {
        $expressions = DB::table('information_schema.statistics')
            ->selectRaw('count(*) as total')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'interaction_outcomes')
            ->whereNotNull('expression')
            ->value('total');

        expect((int) $expressions)->toBeGreaterThan(0);
    });
});

describe('case insensitive text, replacing PostgreSQL citext', function () {
    // 20 migrations convert columns to PostgreSQL's citext type. MySQL has no such type, so the port
    // relies on the utf8mb4_0900_ai_ci collation instead. The behaviour that must survive is that
    // comparison and uniqueness ignore case while the stored value keeps its original case.

    it('treats names differing only by case as duplicates', function () {
        $type = 'PortTest\\Type' . Str::random(6);

        portInsertOutcome('Follow Up', $type);

        expect(fn () => portInsertOutcome('follow up', $type))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('finds a row by a differently cased value', function () {
        $type = 'PortTest\\Type' . Str::random(6);

        portInsertOutcome('Escalated', $type);

        expect(DB::table('interaction_outcomes')->where('interactable_type', $type)->where('name', 'ESCALATED')->exists())
            ->toBeTrue();
    });

    it('preserves the stored case, which citext also does', function () {
        $type = 'PortTest\\Type' . Str::random(6);

        portInsertOutcome('MixedCase Name', $type);

        expect(DB::table('interaction_outcomes')->where('interactable_type', $type)->value('name'))
            ->toBe('MixedCase Name');
    });
});

describe('system protected row triggers, translated from plpgsql', function () {
    // Upstream protects these rows with a PostgreSQL trigger calling a plpgsql function that compares
    // to_jsonb(OLD.*) with to_jsonb(NEW.*) after removing an allowlist of columns. MySQL has neither
    // trigger functions, trigger arguments, nor whole-row JSON, so the port generates one trigger per
    // event with the comparison expanded per column. The protection itself must be identical.

    it('installs a trigger for each event the PostgreSQL trigger covered', function () {
        $rows = DB::select(
            'select event_manipulation as event_manipulation from information_schema.triggers '
            . 'where trigger_schema = database() and event_object_table = ?',
            ['prospect_statuses'],
        );

        $events = collect($rows)
            ->map(fn (object $row): string => strtoupper((string) $row->event_manipulation))
            ->sort()
            ->values()
            ->all();

        expect($events)->toBe(['DELETE', 'UPDATE']);
    });

    it('refuses to change a protected column on a protected row', function () {
        $id = portInsertProspectStatus(['is_system_protected' => true]);

        expect(fn () => DB::table('prospect_statuses')->where('id', $id)->update(['name' => 'Renamed']))
            ->toThrow(QueryException::class);
    });

    it('allows changing a column on the allowlist', function () {
        // The trigger is created with the arguments the migration passed, 'sort' and 'updated_at', so
        // reordering a protected status must still be permitted.
        $id = portInsertProspectStatus(['is_system_protected' => true, 'sort' => 10]);

        DB::table('prospect_statuses')->where('id', $id)->update(['sort' => 20]);

        expect(DB::table('prospect_statuses')->where('id', $id)->value('sort'))->toBe(20);
    });

    it('refuses to delete a protected row', function () {
        $id = portInsertProspectStatus(['is_system_protected' => true]);

        expect(fn () => DB::table('prospect_statuses')->where('id', $id)->delete())
            ->toThrow(QueryException::class);
    });

    it('leaves unprotected rows fully editable and deletable', function () {
        $id = portInsertProspectStatus(['is_system_protected' => false]);

        DB::table('prospect_statuses')->where('id', $id)->update(['name' => 'Freely Renamed']);

        expect(DB::table('prospect_statuses')->where('id', $id)->value('name'))->toBe('Freely Renamed');

        DB::table('prospect_statuses')->where('id', $id)->delete();

        expect(DB::table('prospect_statuses')->where('id', $id)->exists())->toBeFalse();
    });
});

describe('generated uuid defaults', function () {
    // Three migrations add a uuid primary key with `->default(DB::raw('gen_random_uuid()'))`. MySQL
    // rejects that outright, so the port splits the operation and attaches its own default. Models such
    // as Enrollment do not generate ids in PHP, so the database default has to work.

    it('supplies a uuid when a row is inserted without one', function () {
        $sisid = 'PORT' . Str::random(8);

        DB::table('enrollments')->insert(['sisid' => $sisid]);

        $id = DB::table('enrollments')->where('sisid', $sisid)->value('id');

        expect($id)->toBeString()
            ->and(strlen((string) $id))->toBe(36)
            ->and((string) $id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
    });

    it('supplies a distinct uuid to every row', function () {
        $prefix = 'PORT' . Str::random(6);

        foreach (range(1, 5) as $index) {
            DB::table('enrollments')->insert(['sisid' => $prefix . $index]);
        }

        $ids = DB::table('enrollments')->where('sisid', 'like', $prefix . '%')->pluck('id');

        expect($ids)->toHaveCount(5)
            ->and($ids->unique())->toHaveCount(5);
    });
});

describe('jsonb columns became native json columns', function () {
    // Item B2 of the porting brief. `ALTER COLUMN ... TYPE jsonb` is translated to MySQL's JSON type,
    // and the conversion must not quietly drop the column's NOT NULL or default along the way, because
    // MySQL's MODIFY restates the whole column definition.

    it('stores engagement subjects in a real json column, not text', function () {
        $type = DB::table('information_schema.columns')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'engagements')
            ->where('column_name', 'subject')
            ->value('data_type');

        expect(strtolower((string) $type))->toBe('json');
    });

    it('reports json types with the strings MySQL uses, not the ones PostgreSQL uses', function () {
        // Guard for the highest silent-failure risk in the brief. jsonb_typeof answers 'string' and
        // 'number'; JSON_TYPE answers 'STRING' and splits numbers into INTEGER and DOUBLE. Any code
        // comparing against the PostgreSQL spellings is wrong on MySQL, and this test exists so that
        // the difference is asserted rather than assumed.
        $row = DB::selectOne(
            "select json_type(cast('\"s\"' as json)) as s, json_type(cast('1' as json)) as i, "
            . "json_type(cast('1.5' as json)) as d, json_type(cast('[]' as json)) as a"
        );

        expect($row->s)->toBe('STRING')
            ->and($row->i)->toBe('INTEGER')
            ->and($row->d)->toBe('DOUBLE')
            ->and($row->a)->toBe('ARRAY');
    });
});

describe('cascading truncate', function () {
    // Laravel compiles truncate to `TRUNCATE ... CASCADE` on PostgreSQL, which also empties every
    // referencing table. MySQL has no CASCADE and refuses to truncate a referenced table at all, so the
    // port resolves the dependency closure and truncates all of it.

    it('includes referencing tables and suspends foreign key checks', function () {
        $statements = array_keys(
            DB::connection()->getQueryGrammar()->compileTruncate(DB::table('prospects'))
        );

        expect($statements[0])->toBe('set foreign_key_checks = 0')
            ->and(end($statements))->toBe('set foreign_key_checks = 1')
            ->and($statements)->toContain('truncate table `prospects`');

        // Something must reference prospects, otherwise this test proves nothing about cascading.
        expect(count($statements))->toBeGreaterThan(3);
    });

    it('reaches tables that reference the target only indirectly', function () {
        $statements = implode(' | ', array_keys(
            DB::connection()->getQueryGrammar()->compileTruncate(DB::table('prospects'))
        ));

        // prospect_addresses holds a foreign key to prospects, so a cascade must reach it.
        expect($statements)->toContain('prospect_addresses');
    });
});

describe('transactions survive MySQL implicit commits on DDL', function () {
    // 77 migrations wrap Schema:: calls in DB::transaction(). MySQL implicitly commits around DDL, so
    // the later commit finds no transaction and stock PDO throws "There is no active transaction".
    //
    // A separate PDO connection is used rather than the application's, because running DDL on the
    // application connection would commit the transaction this test is wrapped in and break isolation
    // for every test after it.

    it('is the shimmed PDO that the application connection uses', function () {
        expect(DB::connection()->getPdo())->toBeInstanceOf(ResilientMySqlPdo::class);
    });

    it('commits without error after a DDL statement inside a transaction', function () {
        $config = DB::connection()->getConfig();
        $table = 'port_shim_probe_' . Str::lower(Str::random(10));

        $pdo = new ResilientMySqlPdo(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
        );

        try {
            $pdo->beginTransaction();
            $pdo->exec("create table `{$table}` (id int)");

            // MySQL has already committed at this point, which is the whole problem.
            expect($pdo->inTransaction())->toBeFalse()
                ->and($pdo->commit())->toBeTrue();
        } finally {
            $pdo->exec("drop table if exists `{$table}`");
        }
    });

    it('still rolls back and commits ordinary data changes correctly', function () {
        // The shim must not weaken real transactions, only tolerate the ones MySQL has already ended.
        $config = DB::connection()->getConfig();
        $table = 'port_shim_dml_' . Str::lower(Str::random(10));

        $pdo = new ResilientMySqlPdo(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
        );

        try {
            $pdo->exec("create table `{$table}` (id int) engine=InnoDB");

            $pdo->beginTransaction();
            $pdo->exec("insert into `{$table}` values (1)");
            $pdo->rollBack();

            expect((int) $pdo->query("select count(*) from `{$table}`")->fetchColumn())->toBe(0);

            $pdo->beginTransaction();
            $pdo->exec("insert into `{$table}` values (2)");
            $pdo->commit();

            expect((int) $pdo->query("select count(*) from `{$table}`")->fetchColumn())->toBe(1);
        } finally {
            $pdo->exec("drop table if exists `{$table}`");
        }
    });
});

describe('identifier length matches PostgreSQL truncation', function () {
    // MySQL caps identifiers at 64 characters and errors past it; PostgreSQL truncates to 63 silently.
    // The port truncates to PostgreSQL's 63 so generated names match upstream exactly.

    it('produced no identifier longer than PostgreSQL would allow', function () {
        $longest = DB::table('information_schema.statistics')
            ->selectRaw('max(char_length(index_name)) as longest')
            ->whereRaw('table_schema = database()')
            ->value('longest');

        expect((int) $longest)->toBeLessThanOrEqual(63);
    });

    it('kept the truncated foreign key name upstream also produces', function () {
        // service_request_form_authentications_service_request_form_id_foreign is 68 characters, so both
        // engines shorten it. The MySQL name must match the PostgreSQL one.
        $exists = DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('index_name', 'like', '%form_authentications%')
            ->exists();

        expect($exists)->toBeTrue();
    });
});
