<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Translates PostgreSQL's `ALTER TABLE t DROP CONSTRAINT name`, which MySQL does not support in that
 * generic form. MySQL needs to be told what kind of thing is being dropped.
 *
 * 18 of these appear in raw SQL, chiefly where morph columns are converted from uuid to string, for
 * example
 * `app-modules/prospect/database/migrations/2026_03_18_163527_change_morphed_id_columns_from_uuid_to_string.php`
 * dropping `prospects_pkey`.
 *
 * The kind is resolved from `information_schema` rather than inferred from the name, and each maps to
 * the specific MySQL statement:
 *
 * - a primary key becomes `DROP PRIMARY KEY`, since MySQL always calls it `PRIMARY` and PostgreSQL
 *   calls it `<table>_pkey`,
 * - a foreign key becomes `DROP FOREIGN KEY name`,
 * - a unique or check constraint becomes `DROP INDEX name` or `DROP CHECK name`.
 *
 * A constraint that cannot be found raises, rather than being skipped, because a later migration that
 * recreates or references it would then fail somewhere much harder to diagnose.
 */
class DropConstraintTranslator
{
    private const PATTERN = '/^\s*ALTER\s+TABLE\s+"?(?<table>[a-zA-Z0-9_]+)"?\s+'
        . 'DROP\s+CONSTRAINT\s+(?<ifexists>IF\s+EXISTS\s+)?"?(?<name>[a-zA-Z0-9_]+)"?\s*;?\s*$/is';

    public static function handles(string $query): bool
    {
        return preg_match(self::PATTERN, $query) === 1;
    }

    /**
     * @return list<string>
     */
    public static function translate(Connection $connection, string $query): array
    {
        if (preg_match(self::PATTERN, $query, $matches) !== 1) {
            throw new RuntimeException("Could not parse the constraint drop [{$query}].");
        }

        $table = $matches['table'];
        $name = $matches['name'];

        $isForeignKey = $connection->selectOne(
            'select constraint_name as fk_name from information_schema.referential_constraints '
            . 'where constraint_schema = database() and table_name = ? and constraint_name = ? limit 1',
            [$table, $name],
        );

        if ($isForeignKey !== null) {
            return ["alter table `{$table}` drop foreign key `{$name}`"];
        }

        $index = $connection->selectOne(
            'select index_name as index_name from information_schema.statistics '
            . 'where table_schema = database() and table_name = ? and index_name = ? limit 1',
            [$table, $name],
        );

        if ($index !== null) {
            return ["alter table `{$table}` drop index `{$name}`"];
        }

        $check = $connection->selectOne(
            'select constraint_name as check_name from information_schema.table_constraints '
            . 'where constraint_schema = database() and table_name = ? and constraint_name = ? '
            . "and constraint_type = 'CHECK' limit 1",
            [$table, $name],
        );

        if ($check !== null) {
            return ["alter table `{$table}` drop check `{$name}`"];
        }

        // PostgreSQL names a primary key `<table>_pkey`. MySQL names every primary key `PRIMARY`, so
        // the name never matches and the drop has to be expressed as DROP PRIMARY KEY instead.
        if (str_ends_with($name, '_pkey') && self::hasPrimaryKey($connection, $table)) {
            return ["alter table `{$table}` drop primary key"];
        }

        if (trim((string) $matches['ifexists']) !== '') {
            return [];
        }

        throw new RuntimeException(
            "The MySQL port cannot drop constraint [{$name}] on table [{$table}] because no foreign key, "
            . 'index, check constraint or primary key matches it. Skipping silently would leave the '
            . 'schema in a state later migrations do not expect.'
        );
    }

    private static function hasPrimaryKey(Connection $connection, string $table): bool
    {
        return $connection->selectOne(
            'select index_name as index_name from information_schema.statistics '
            . "where table_schema = database() and table_name = ? and index_name = 'PRIMARY' limit 1",
            [$table],
        ) !== null;
    }
}
