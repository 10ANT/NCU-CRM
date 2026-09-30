<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Translates PostgreSQL's `ALTER TABLE ... RENAME CONSTRAINT old TO new`, which MySQL has no
 * equivalent for, into statements MySQL does accept.
 *
 * Four migrations issue 104 of these as raw `DB::statement()` calls while renaming the knowledge base
 * to the resource hub and service requests to cases, for example
 * `app-modules/resource-hub/database/migrations/2024_11_05_102357_rename_knowledge_base_to_resource_hub.php:49`.
 * Because they are raw SQL rather than schema-builder commands, no grammar ever sees them, which is
 * why the translation is applied at the connection instead.
 *
 * Translating in one place rather than rewriting 104 lines across four upstream migrations keeps the
 * diff against upstream at zero for those files, and covers any further renames that arrive with a
 * future upstream merge.
 *
 * Three cases, each resolved from `information_schema` rather than guessed from the name:
 *
 * - **Primary keys.** MySQL always calls the primary key `PRIMARY` and offers no way to name it, so a
 *   rename has nothing to act on and becomes a no-op. No capability is lost: the primary key itself is
 *   untouched and still enforced. Only the label PostgreSQL would have shown differs, and MySQL never
 *   had that label to begin with.
 * - **Foreign keys.** MySQL cannot rename a constraint, so the constraint is dropped and recreated
 *   under the new name, carrying over its columns, referenced columns and its ON DELETE and ON UPDATE
 *   actions so the constraint that ends up in place is the same one.
 * - **Unique and plain indexes.** `ALTER TABLE ... RENAME INDEX` does exactly the right thing.
 *
 * A constraint that cannot be found and is not a primary key is an error rather than a silent skip,
 * because quietly doing nothing would leave a foreign key named wrongly and a later migration
 * referring to it would fail somewhere far less obvious.
 */
class RenameConstraintTranslator
{
    /**
     * Whether a statement is one this translator handles.
     */
    public static function handles(string $query): bool
    {
        return stripos($query, 'RENAME CONSTRAINT') !== false
            || preg_match('/^\s*ALTER\s+INDEX\s/i', $query) === 1;
    }

    /**
     * Translate PostgreSQL's `ALTER INDEX old RENAME TO new`.
     *
     * MySQL can rename an index, but only as `ALTER TABLE <table> RENAME INDEX old TO new`, and
     * PostgreSQL's form does not mention the table because index names are schema-wide there. The
     * table is therefore resolved from `information_schema`.
     *
     * Four of these appear in
     * `database/migrations/Legacy/2024_11_22_075206_rename_service_request_related_data_to_case.php`.
     *
     * @return list<string>
     */
    private static function translateIndexRename(Connection $connection, string $query): array
    {
        if (preg_match(
            '/^\s*ALTER\s+INDEX\s+(?:IF\s+EXISTS\s+)?"?(?<from>[a-zA-Z0-9_]+)"?\s+RENAME\s+TO\s+"?(?<to>[a-zA-Z0-9_]+)"?\s*;?\s*$/is',
            $query,
            $matches,
        ) !== 1) {
            throw new RuntimeException("Could not parse the index rename [{$query}].");
        }

        $from = $matches['from'];
        $to = $matches['to'];

        $owner = $connection->selectOne(
            'select table_name as owning_table from information_schema.statistics '
            . 'where table_schema = database() and index_name = ? limit 1',
            [$from],
        );

        if ($owner === null) {
            if (stripos($query, 'IF EXISTS') !== false) {
                return [];
            }

            throw new RuntimeException(
                "The MySQL port cannot rename index [{$from}] because no index by that name exists in "
                . 'this schema. Skipping silently would leave it misnamed for later migrations.'
            );
        }

        $table = (string) $owner->owning_table;

        return ["alter table `{$table}` rename index `{$from}` to `{$to}`"];
    }

    /**
     * Translate one rename into the equivalent MySQL statements.
     *
     * @return list<string> possibly empty, when the rename is a no-op on MySQL
     */
    public static function translate(Connection $connection, string $query): array
    {
        if (preg_match('/^\s*ALTER\s+INDEX\s/i', $query) === 1) {
            return self::translateIndexRename($connection, $query);
        }

        if (preg_match(
            '/^\s*ALTER\s+TABLE\s+"?(?<table>[a-zA-Z0-9_]+)"?\s+RENAME\s+CONSTRAINT\s+"?(?<from>[a-zA-Z0-9_]+)"?\s+TO\s+"?(?<to>[a-zA-Z0-9_]+)"?\s*;?\s*$/is',
            $query,
            $matches,
        ) !== 1) {
            throw new RuntimeException("Could not parse the constraint rename [{$query}].");
        }

        $table = $matches['table'];
        $from = $matches['from'];
        $to = $matches['to'];

        $foreignKey = $connection->selectOne(
            'select rc.delete_rule as delete_rule, rc.update_rule as update_rule, '
            . 'kcu.referenced_table_name as referenced_table '
            . 'from information_schema.referential_constraints rc '
            . 'join information_schema.key_column_usage kcu '
            . '  on kcu.constraint_schema = rc.constraint_schema '
            . ' and kcu.constraint_name = rc.constraint_name '
            . 'where rc.constraint_schema = database() and rc.table_name = ? and rc.constraint_name = ? '
            . 'limit 1',
            [$table, $from],
        );

        if ($foreignKey !== null) {
            return self::recreateForeignKey($connection, $table, $from, $to, $foreignKey);
        }

        $index = $connection->selectOne(
            'select index_name as index_name from information_schema.statistics '
            . 'where table_schema = database() and table_name = ? and index_name = ? limit 1',
            [$table, $from],
        );

        if ($index !== null) {
            return ["alter table `{$table}` rename index `{$from}` to `{$to}`"];
        }

        // PostgreSQL names a table's primary key constraint `<table>_pkey`. MySQL has no such name and
        // no way to set one, so there is nothing to rename.
        if (str_ends_with($from, '_pkey')) {
            return [];
        }

        throw new RuntimeException(
            "The MySQL port cannot rename constraint [{$from}] on table [{$table}] because no foreign "
            . 'key or index by that name exists. Doing nothing would leave the constraint misnamed for '
            . 'every later migration that refers to it, so this is being raised instead.'
        );
    }

    /**
     * Drop a foreign key and recreate it under a new name, preserving its definition.
     *
     * @return list<string>
     */
    private static function recreateForeignKey(
        Connection $connection,
        string $table,
        string $from,
        string $to,
        object $foreignKey,
    ): array {
        $columns = $connection->select(
            'select column_name as column_name, referenced_column_name as referenced_column '
            . 'from information_schema.key_column_usage '
            . 'where table_schema = database() and table_name = ? and constraint_name = ? '
            . 'order by ordinal_position',
            [$table, $from],
        );

        if ($columns === []) {
            throw new RuntimeException("Foreign key [{$from}] on [{$table}] has no columns recorded.");
        }

        $local = implode(', ', array_map(
            static fn (object $column): string => '`' . $column->column_name . '`',
            $columns,
        ));

        $referenced = implode(', ', array_map(
            static fn (object $column): string => '`' . $column->referenced_column . '`',
            $columns,
        ));

        $referencedTable = (string) $foreignKey->referenced_table;

        // MySQL reports NO ACTION where none was specified; it behaves as RESTRICT. Both are emitted
        // verbatim so the recreated constraint matches whatever the original actually had.
        $onDelete = ' on delete ' . strtolower((string) $foreignKey->delete_rule);
        $onUpdate = ' on update ' . strtolower((string) $foreignKey->update_rule);

        return [
            "alter table `{$table}` drop foreign key `{$from}`",
            "alter table `{$table}` add constraint `{$to}` foreign key ({$local}) "
                . "references `{$referencedTable}` ({$referenced}){$onDelete}{$onUpdate}",
        ];
    }
}
