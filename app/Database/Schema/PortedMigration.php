<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Support\Facades\Schema;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * A few upstream migrations exist purely to teach PostgreSQL something MySQL already knows, or to
 * install a PostgreSQL type or extension that MySQL has no notion of. Those migrations have to stand
 * down on MySQL, and the ones that would otherwise run PostgreSQL-only SQL have to be guarded.
 *
 * The guard is written as a driver check rather than by deleting or emptying the migration, for two
 * reasons. Keeping the file means migration history stays aligned with upstream, so a future
 * `git pull` still lines up. Keeping its PostgreSQL branch means this checkout remains a working
 * PostgreSQL application, which matters because the port is required to replace implementations
 * without removing capability.
 *
 * A guard is only ever legitimate where the capability survives the skip. `MAX()` over a UUID column
 * is the clear case: PostgreSQL needs a custom aggregate to do it, MySQL does it natively on
 * `char(36)`, so skipping the aggregate on MySQL costs nothing. Where a skip would genuinely remove
 * behaviour, the translation has to be implemented instead, and that work belongs in
 * {@see MySqlEnhancedSchemaMacros} or in the migration itself.
 */
class PortedMigration
{
    /**
     * Whether the migration currently running is on PostgreSQL.
     *
     * Read from the schema builder rather than from config, so it reflects the connection the
     * migrator actually chose. Tenant and landlord migrations run on different connections.
     */
    public static function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    public static function isMySql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }
}
