<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Schema\MySqlBuilder;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Makes `dropAllTables()` also drop views, which is what upstream gets for free on PostgreSQL.
 *
 * Laravel's PostgreSQL grammar compiles the wholesale drop as `drop table a, b, c cascade`
 * (`Illuminate\Database\Schema\Grammars\PostgresGrammar::compileDropAllTables()`, line 541), and
 * PostgreSQL's CASCADE removes every view built on those tables as well. MySQL's equivalent is a plain
 * `drop table`, so views survive.
 *
 * That matters because `App\Jobs\MigrateTenantDatabase` provisions a tenant with `migrate:fresh
 * --force`, and `migrate:fresh` only drops views when given `--drop-views`. On MySQL the leftover views
 * then collide with the migrations that recreate them, since MySQL keeps tables and views in one
 * namespace: `create view holistic_engagements` fails because the view is still there, and a table
 * sharing a name with a leftover view fails the same way.
 *
 * Dropping views first also respects the dependency order, since a view can depend on a table but not
 * the reverse.
 */
class PortedMySqlSchemaBuilder extends MySqlBuilder
{
    /**
     * Drop all tables from the database, along with any views built on them.
     *
     * @return void
     */
    public function dropAllTables()
    {
        $this->dropAllViews();

        parent::dropAllTables();
    }
}
