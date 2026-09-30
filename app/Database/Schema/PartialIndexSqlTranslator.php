<?php

declare(strict_types=1);

namespace App\Database\Schema;

use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Translates raw `CREATE [UNIQUE] INDEX ... WHERE ...` statements, PostgreSQL partial indexes, into
 * the MySQL functional indexes that enforce the same rule.
 *
 * Most partial indexes in this project are declared through the PostgreSQL-enhanced schema builder and
 * are handled by {@see MySqlEnhancedSchemaMacros}. Five migrations write them as raw SQL instead, for
 * example `app-modules/interaction/database/migrations/2025_03_17_170737_update_unique_key_in_interaction_outcomes_table.php`:
 *
 *     CREATE UNIQUE INDEX interaction_outcomes_is_default_unique
 *     ON interaction_outcomes (is_default)
 *     WHERE is_default = true AND deleted_at IS NULL;
 *
 * Raw SQL never reaches a grammar, so the translation is applied at the connection.
 *
 * The rule being preserved is "at most one default outcome among rows that are not soft-deleted".
 * MySQL has no partial indexes, but it has functional key parts and it does not treat NULL key parts as
 * duplicates, so indexing `CASE WHEN <condition> THEN <column> END` enforces exactly that: rows failing
 * the condition index as NULL and never collide. Verified against MySQL 8.4.11.
 *
 * Dropping the WHERE clause instead would turn this into a plain unique index and forbid a second
 * non-default row entirely, which would break the feature. That is why this is translated rather than
 * simplified.
 */
class PartialIndexSqlTranslator
{
    private const PATTERN = '/^\s*CREATE\s+(?<unique>UNIQUE\s+)?INDEX\s+(?<ifnotexists>IF\s+NOT\s+EXISTS\s+)?'
        . '"?(?<name>[a-zA-Z0-9_]+)"?\s+ON\s+"?(?<table>[a-zA-Z0-9_]+)"?\s*'
        . '\((?<columns>[^)]+)\)\s*WHERE\s+(?<condition>.+?)\s*;?\s*$/is';

    public static function handles(string $query): bool
    {
        return preg_match(self::PATTERN, $query) === 1;
    }

    /**
     * @return list<string>
     */
    public static function translate(string $query): array
    {
        if (preg_match(self::PATTERN, $query, $matches) !== 1) {
            throw new RuntimeException("Could not parse the partial index statement [{$query}].");
        }

        $condition = PartialIndexCondition::normalise(trim($matches['condition']));

        $columns = array_map(
            static fn (string $column): string => trim($column, " \t\n\r\"`"),
            explode(',', $matches['columns']),
        );

        $keyParts = implode(', ', array_map(
            static fn (string $column): string => sprintf('(case when %s then `%s` end)', $condition, $column),
            $columns,
        ));

        $unique = trim((string) $matches['unique']) === '' ? '' : 'unique ';

        return [sprintf(
            'create %sindex `%s` on `%s` (%s)',
            $unique,
            $matches['name'],
            $matches['table'],
            $keyParts,
        )];
    }
}
