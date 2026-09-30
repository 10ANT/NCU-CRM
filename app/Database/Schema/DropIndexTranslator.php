<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Translates PostgreSQL's standalone `DROP INDEX [IF EXISTS] name`.
 *
 * Index names are schema-wide in PostgreSQL, so dropping one needs no table name. MySQL scopes index
 * names to their table and offers no `IF EXISTS` for them, so the owning table is resolved from
 * `information_schema` and the statement becomes `ALTER TABLE t DROP INDEX name`.
 *
 * Used by migrations that replace an index with a different definition, for example
 * `app-modules/interaction/database/migrations/2025_12_24_002713_data_add_interactable_type_to_interactions.php`.
 *
 * When the index does not exist, `IF EXISTS` makes that a no-op, matching PostgreSQL. Without
 * `IF EXISTS` a missing index is an error, again matching PostgreSQL, rather than being passed over
 * silently.
 */
class DropIndexTranslator
{
    private const PATTERN = '/^\s*DROP\s+INDEX\s+(?<ifexists>IF\s+EXISTS\s+)?"?(?<name>[a-zA-Z0-9_]+)"?\s*;?\s*$/is';

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
            throw new RuntimeException("Could not parse the index drop [{$query}].");
        }

        $name = $matches['name'];

        $owner = $connection->selectOne(
            'select table_name as owning_table from information_schema.statistics '
            . 'where table_schema = database() and index_name = ? limit 1',
            [$name],
        );

        if ($owner === null) {
            if (trim((string) $matches['ifexists']) !== '') {
                return [];
            }

            throw new RuntimeException("Cannot drop index [{$name}]: no index by that name exists.");
        }

        return ['alter table `' . $owner->owning_table . '` drop index `' . $name . '`'];
    }
}
