<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Translates PostgreSQL's `ALTER TABLE t ALTER COLUMN c TYPE x [USING ...]`, which MySQL does not
 * accept, into MySQL's `ALTER TABLE t MODIFY c ...`.
 *
 * 23 migrations issue these as raw SQL. Counted by target type: uuid 12, text 9, varchar 6, jsonb 4,
 * citext 3, integer 1, date 1. An example is
 * `app-modules/engagement/database/migrations/2025_04_17_161126_change_subject_column_to_jsonb_in_engagement_tables.php:46`:
 *
 *     ALTER TABLE engagements ALTER COLUMN subject TYPE jsonb USING subject::jsonb
 *
 * PostgreSQL's `USING` clause supplies an expression to convert existing values. MySQL converts in
 * place with its own coercion rules and has no equivalent clause, so the `USING` part is dropped. That
 * is safe for the conversions here, which are all widening or representational (text to json, varchar
 * to uuid) rather than lossy reinterpretations.
 *
 * The important subtlety, and the reason this is not a one-line string replacement: MySQL's `MODIFY`
 * restates a column's **entire** definition, so anything omitted is discarded. Writing
 * `MODIFY subject JSON` against a `NOT NULL` column would quietly make it nullable. The current
 * nullability, default and comment are therefore read back from `information_schema` and re-emitted.
 * Dropping a NOT NULL constraint is exactly the kind of silent weakening this port must not introduce.
 */
class AlterColumnTypeTranslator
{
    private const PATTERN = '/^\s*ALTER\s+TABLE\s+"?(?<table>[a-zA-Z0-9_]+)"?\s+'
        . 'ALTER\s+COLUMN\s+"?(?<column>[a-zA-Z0-9_]+)"?\s+'
        . 'TYPE\s+(?<type>[a-zA-Z0-9_]+(?:\s*\(\s*\d+(?:\s*,\s*\d+)?\s*\))?)'
        . '(?<using>\s+USING\s+.+?)?\s*;?\s*$/is';

    /**
     * MySQL equivalents for the PostgreSQL types these migrations convert to.
     *
     * `citext` is deliberately absent; it is handled separately because it becomes a no-op.
     */
    private const TYPE_MAP = [
        'jsonb' => 'json',
        'json' => 'json',
        'uuid' => 'char(36)',
        'text' => 'text',
        'integer' => 'int',
        'int' => 'int',
        'bigint' => 'bigint',
        'boolean' => 'tinyint(1)',
        'date' => 'date',
    ];

    /**
     * Types MySQL refuses to give a literal default.
     */
    private const NO_LITERAL_DEFAULT = ['json', 'text', 'blob'];

    public static function handles(string $query): bool
    {
        return preg_match(self::PATTERN, $query) === 1;
    }

    /**
     * @return list<string> empty when the conversion is a no-op on MySQL
     */
    public static function translate(Connection $connection, string $query): array
    {
        if (preg_match(self::PATTERN, $query, $matches) !== 1) {
            throw new RuntimeException("Could not parse the column type change [{$query}].");
        }

        $table = $matches['table'];
        $column = $matches['column'];
        $requested = strtolower(trim($matches['type']));

        // PostgreSQL's citext is a case-insensitive text type. Its MySQL counterpart is the collation,
        // which is already utf8mb4_0900_ai_ci server-wide for this installation, so the column is
        // case-insensitive before the statement runs and there is nothing to change. Converting it to
        // some other type here would actively alter the schema rather than leave it correct.
        if (str_starts_with($requested, 'citext')) {
            return [];
        }

        $type = self::mysqlType($requested);

        if ($type === 'text') {
            $type = self::indexableTextType($connection, $table, $column);
        }

        $definition = $connection->selectOne(
            'select is_nullable as is_nullable, column_default as column_default, '
            . 'column_comment as column_comment, extra as extra '
            . 'from information_schema.columns '
            . 'where table_schema = database() and table_name = ? and column_name = ? limit 1',
            [$table, $column],
        );

        if ($definition === null) {
            throw new RuntimeException("Column [{$column}] does not exist on table [{$table}].");
        }

        $sql = sprintf('alter table `%s` modify `%s` %s', $table, $column, $type);

        $sql .= strtoupper((string) $definition->is_nullable) === 'NO' ? ' not null' : ' null';

        $sql .= self::defaultClause($type, $definition);

        $comment = (string) ($definition->column_comment ?? '');

        if ($comment !== '') {
            $sql .= " comment '" . str_replace("'", "''", $comment) . "'";
        }

        return [$sql];
    }

    /**
     * Pick a type for a column PostgreSQL would have made `text`.
     *
     * PostgreSQL's `text` is an unbounded string that indexes like any other. MySQL's `TEXT` cannot be
     * indexed at all without a prefix length, so `ALTER TABLE ... MODIFY col TEXT` fails with error
     * 1170 on any indexed column. This is reached by
     * `app-modules/prospect/database/migrations/2026_03_18_163527_change_morphed_id_columns_from_uuid_to_string.php`,
     * which widens morph id columns so they can hold keys that are not UUIDs.
     *
     * `varchar(255)` is used instead, which indexes normally and is the same type Laravel's own
     * `string()` produces.
     *
     * This is applied to every `TYPE text` conversion rather than only to columns that are indexed at
     * the moment of conversion. All nine such conversions in this codebase live in that one migration
     * and every one is a key column (`id`, `prospect_id`, `case_id`) being widened from uuid to string;
     * none is a long-content field. Checking for an existing index is not enough, because the migration
     * drops the primary key, converts the column, then adds the key back, so at conversion time the
     * column looks unindexed and `ADD PRIMARY KEY` then fails with the same error 1170.
     *
     * Because `varchar(255)` is bounded where PostgreSQL's `text` is not, existing data is checked
     * first. If anything already exceeds 255 characters the conversion is refused rather than
     * truncating, since silently losing data would be far worse than a failed migration.
     */
    private static function indexableTextType(Connection $connection, string $table, string $column): string
    {
        $longest = $connection->selectOne(
            sprintf('select max(char_length(`%s`)) as longest from `%s`', $column, $table),
        );

        $length = (int) ($longest->longest ?? 0);

        if ($length > 255) {
            throw new RuntimeException(
                "Refusing to convert column [{$table}.{$column}] to varchar(255): it already "
                . "holds a value {$length} characters long, and MySQL cannot index an unbounded TEXT "
                . 'column. Widen the type deliberately rather than truncating data.'
            );
        }

        return 'varchar(255)';
    }

    private static function mysqlType(string $requested): string
    {
        // Parameterised types such as varchar(255) and numeric(8,2) carry their own length through.
        if (preg_match('/^(?<base>[a-z]+)\s*\(\s*(?<args>[\d\s,]+)\)$/i', $requested, $parts) === 1) {
            $base = strtolower($parts['base']);
            $args = preg_replace('/\s+/', '', $parts['args']);

            $base = match ($base) {
                'varchar', 'char', 'decimal' => $base,
                'numeric' => 'decimal',
                default => self::TYPE_MAP[$base] ?? throw new RuntimeException(
                    "The MySQL port has no mapping for the PostgreSQL type [{$requested}]."
                ),
            };

            return "{$base}({$args})";
        }

        return self::TYPE_MAP[$requested] ?? throw new RuntimeException(
            "The MySQL port has no mapping for the PostgreSQL type [{$requested}]. Add a mapping rather "
            . 'than letting the column silently keep its old type.'
        );
    }

    /**
     * Re-emit the column's existing default, where MySQL allows the type to have one.
     */
    private static function defaultClause(string $type, object $definition): string
    {
        $default = $definition->column_default ?? null;

        if ($default === null) {
            return '';
        }

        foreach (self::NO_LITERAL_DEFAULT as $bare) {
            if (str_starts_with($type, $bare)) {
                return '';
            }
        }

        // MySQL reports generated defaults such as CURRENT_TIMESTAMP as expressions rather than
        // literals, and flags them in `extra`. Those are emitted verbatim, not quoted.
        if (str_contains(strtoupper((string) ($definition->extra ?? '')), 'DEFAULT_GENERATED')) {
            return ' default ' . $default;
        }

        return " default '" . str_replace("'", "''", (string) $default) . "'";
    }
}
