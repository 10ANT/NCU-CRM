<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use SensitiveParameter;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Substitutes {@see ResilientMySqlPdo} for the stock PDO instance on MySQL connections, so that
 * `DB::transaction()` blocks containing DDL survive MySQL's implicit commits. See that class for
 * the full explanation.
 *
 * Registered by {@see \App\Providers\DatabasePortServiceProvider} against the container key
 * `db.connector.mysql`, which is the extension point Laravel's `ConnectionFactory` checks before
 * falling back to its own connector. Nothing in the framework is patched, and PostgreSQL
 * connections are entirely unaffected because this connector is only bound for the mysql driver.
 */
class ResilientMySqlConnector extends MySqlConnector
{
    /**
     * Create a new PDO connection instance.
     *
     * @param  string  $dsn
     * @param  string  $username
     * @param  string  $password
     * @param  array<int, mixed>  $options
     */
    protected function createPdoConnection($dsn, $username, #[SensitiveParameter] $password, $options): PDO
    {
        return new ResilientMySqlPdo($dsn, $username, $password, $options);
    }
}
