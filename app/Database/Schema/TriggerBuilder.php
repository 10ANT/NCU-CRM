<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Builds the MySQL statements for one PostgreSQL trigger definition.
 *
 * Shared by two callers so that both produce identical SQL:
 *
 * - {@see MySqlEnhancedSchemaMacros}, when a migration declares a trigger, and
 * - {@see PostgresTriggerRegistry}, when a function is replaced and its triggers have to be rebuilt to
 *   reproduce PostgreSQL's late binding.
 */
class TriggerBuilder
{
    /**
     * @return list<string>
     */
    public static function statements(
        Connection $connection,
        string $trigger,
        string $table,
        string $fire,
        string $action,
    ): array {
        [$function, $arguments] = MySqlEnhancedSchemaMacros::parseTriggerAction($action);

        $body = PostgresFunctionRegistry::body($connection, $function);

        $statements = [];

        foreach (PlPgSqlTriggerTranslator::events($fire) as $event) {
            // PostgreSQL fires one trigger on several events; MySQL needs one trigger per event, so the
            // name is suffixed to keep them distinct and still recognisable.
            $name = self::nameFor($trigger, $event);

            // `->replace(true)` in the migration means create or replace. MySQL has no such clause, so
            // the drop is emitted unconditionally, which also makes the migration re-runnable.
            $statements[] = 'drop trigger if exists ' . self::wrap($name);

            $statements[] = sprintf(
                'create trigger %s %s on %s for each row %s',
                self::wrap($name),
                $event,
                self::wrap($table),
                PlPgSqlTriggerTranslator::translate($connection, $body, $table, $event, $arguments),
            );
        }

        return $statements;
    }

    /**
     * Every MySQL trigger name a single PostgreSQL trigger can expand into.
     *
     * @return list<string>
     */
    public static function possibleNames(string $trigger): array
    {
        $names = [];

        foreach (['BEFORE', 'AFTER'] as $timing) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $names[] = self::nameFor($trigger, "{$timing} {$event}");
            }
        }

        return $names;
    }

    public static function nameFor(string $trigger, string $event): string
    {
        return $trigger . '_' . strtolower(str_replace(' ', '_', $event));
    }

    private static function wrap(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
