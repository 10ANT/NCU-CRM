<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * PostgreSQL triggers delegate their body to a separately created `FUNCTION`, and this project uses
 * that: `Schema::createFunctionOrReplace('prevent_modification_of_system_protected_rows', ...)`
 * defines the logic once, and trigger migrations then point at it by name.
 *
 * MySQL has no equivalent. A MySQL trigger carries its body inline and cannot call a stored
 * procedure that manipulates NEW and OLD. So the body has to be available at the moment a trigger
 * is compiled, which may be a different artisan process from the one that created the function.
 *
 * This registry therefore persists recorded function bodies in the database itself, in a table
 * named `port_postgres_functions`, which mirrors how PostgreSQL treats functions as durable schema
 * objects. Storing them in a static array would break as soon as the function and its trigger were
 * migrated in separate runs.
 */
class PostgresFunctionRegistry
{
    public const TABLE = 'port_postgres_functions';

    public static function record(Connection $connection, string $name, string $body, string $language): void
    {
        self::ensureTableExists($connection);

        $connection->table(self::TABLE)->updateOrInsert(
            ['name' => $name],
            ['body' => $body, 'language' => $language],
        );
    }

    public static function forget(Connection $connection, string $name): void
    {
        self::ensureTableExists($connection);

        $connection->table(self::TABLE)->where('name', $name)->delete();
    }

    /**
     * Fetch a recorded function body, failing loudly if it was never recorded.
     *
     * A missing body must not be treated as "no trigger needed". That would silently drop a
     * data-integrity guarantee, which is exactly the kind of invisible feature loss this port is
     * required to avoid.
     */
    public static function body(Connection $connection, string $name): string
    {
        self::ensureTableExists($connection);

        $body = $connection->table(self::TABLE)->where('name', $name)->value('body');

        if (! is_string($body) || trim($body) === '') {
            throw new RuntimeException(
                "The MySQL port cannot compile a trigger calling [{$name}] because no body for that "
                . 'function was ever recorded. PostgreSQL trigger functions are recorded by the '
                . 'createFunctionOrReplace macro; check that its migration ran against this connection.'
            );
        }

        return $body;
    }

    private static function ensureTableExists(Connection $connection): void
    {
        if ($connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            return;
        }

        $table = $connection->getQueryGrammar()->wrapTable(self::TABLE);

        $connection->statement(
            "create table {$table} ("
            . 'name varchar(255) not null primary key, '
            . 'body longtext not null, '
            . 'language varchar(64) not null'
            . ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_0900_ai_ci'
        );
    }
}
