<?php

declare(strict_types=1);

namespace App\Providers;

use Closure;
use App\Database\MySqlEnhancedBlueprint;
use App\Database\PortedMySqlConnection;
use App\Database\ResilientMySqlConnector;
use App\Database\Schema\MySqlEnhancedSchemaMacros;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use PDO;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Holds the container bindings that let this application run on MySQL as well as on its original
 * PostgreSQL. Everything registered here is conditional on the mysql driver, so a checkout with
 * `DB_DRIVER` unset behaves exactly as upstream does.
 */
class DatabasePortServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Laravel's ConnectionFactory consults this container key before instantiating its own
        // connector, so binding it swaps in our PDO subclass without patching the framework.
        $this->app->bind('db.connector.mysql', fn (): ResilientMySqlConnector => new ResilientMySqlConnector());

        if (! $this->runningOnMySql()) {
            return;
        }

        // Laravel's schema Builder resolves a Blueprint through the container
        // (Illuminate\Database\Schema\Builder::createBlueprint(), line 720), so binding the class
        // here hands every MySQL migration the PostgreSQL-enhanced Blueprint subclass that the 227
        // affected migrations type-hint against. No migration is edited.
        $this->app->bind(Blueprint::class, MySqlEnhancedBlueprint::class);

        // Same extension point the PostgreSQL-enhanced package uses for 'pgsql'. Supplies the query
        // grammar that reproduces PostgreSQL's cascading TRUNCATE.
        Connection::resolverFor('mysql', static fn (PDO|Closure $pdo, string $database = '', string $tablePrefix = '', array $config = []): PortedMySqlConnection => new PortedMySqlConnection($pdo, $database, $tablePrefix, $config));

        MySqlEnhancedSchemaMacros::register();
    }

    /**
     * Whether this installation is configured for MySQL.
     *
     * Read from the environment rather than from a resolved connection, because this runs during
     * register() before the database manager exists.
     */
    private function runningOnMySql(): bool
    {
        return env('DB_DRIVER', 'pgsql') === 'mysql';
    }
}
