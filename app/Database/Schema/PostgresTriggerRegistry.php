<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Restores the late binding that PostgreSQL triggers have and MySQL triggers do not.
 *
 * A PostgreSQL trigger names the function it calls and resolves it at fire time, so replacing the
 * function changes every trigger that uses it, immediately and without touching the triggers. MySQL
 * triggers carry their body inline, so the port has to inline the translated function body when the
 * trigger is created.
 *
 * That difference is not cosmetic, and it caused a real regression that
 * `tests/Tenant/Database/MySqlPortBehaviourTest.php` caught. The migrations run in this order:
 *
 *   1. `2024_10_08_173120` defines the function as a blanket guard: any change to a protected row is
 *      rejected.
 *   2. `2024_10_08_173948` creates the trigger.
 *   3. `2026_01_12_134954` recreates the trigger, now passing an allowlist of `sort` and `updated_at`.
 *   4. `2026_01_12_135213` replaces the function with the allowlist-aware version.
 *
 * Step 4 comes last. With the body inlined at step 3, the trigger kept the blanket guard for ever, so
 * reordering a system-protected prospect status was rejected when upstream allows it. The feature was
 * silently gone while every migration reported success.
 *
 * This registry records the triggers the port generates so that replacing a function can rebuild them,
 * which reproduces PostgreSQL's behaviour regardless of migration order.
 */
class PostgresTriggerRegistry
{
    public const TABLE = 'port_postgres_triggers';

    /**
     * Remember a trigger the port generated, so a later function replacement can rebuild it.
     */
    public static function record(
        Connection $connection,
        string $trigger,
        string $table,
        string $fire,
        string $action,
        string $function,
    ): void {
        self::ensureTableExists($connection);

        $connection->table(self::TABLE)->updateOrInsert(
            ['trigger_name' => $trigger, 'table_name' => $table],
            ['fire' => $fire, 'action' => $action, 'function_name' => $function],
        );
    }

    public static function forget(Connection $connection, string $trigger): void
    {
        self::ensureTableExists($connection);

        $connection->table(self::TABLE)->where('trigger_name', $trigger)->delete();
    }

    /**
     * Rebuild every recorded trigger that calls the given function.
     *
     * Called when a function is created or replaced. Any trigger already generated from an older body
     * is regenerated from the new one, which is what PostgreSQL achieves for free.
     */
    public static function rebuildFor(Connection $connection, string $function): void
    {
        self::ensureTableExists($connection);

        $triggers = $connection->table(self::TABLE)->where('function_name', $function)->get();

        foreach ($triggers as $trigger) {
            foreach (TriggerBuilder::statements(
                $connection,
                (string) $trigger->trigger_name,
                (string) $trigger->table_name,
                (string) $trigger->fire,
                (string) $trigger->action,
            ) as $statement) {
                $connection->statement($statement);
            }
        }
    }

    private static function ensureTableExists(Connection $connection): void
    {
        if ($connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            return;
        }

        $table = $connection->getQueryGrammar()->wrapTable(self::TABLE);

        $connection->statement(
            "create table {$table} ("
            . 'trigger_name varchar(191) not null, '
            . 'table_name varchar(191) not null, '
            . 'fire varchar(191) not null, '
            . 'action text not null, '
            . 'function_name varchar(191) not null, '
            . 'primary key (trigger_name, table_name), '
            . 'key port_postgres_triggers_function_name_index (function_name)'
            . ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_0900_ai_ci'
        );
    }
}
