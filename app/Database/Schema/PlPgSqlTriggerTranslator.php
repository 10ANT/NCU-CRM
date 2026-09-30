<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Translates the plpgsql trigger-function bodies this project defines into MySQL trigger bodies.
 *
 * This is a deliberately bounded translator, not a general plpgsql compiler. It recognises the
 * specific constructs this codebase uses and throws on anything else. That choice is the point:
 * a general translator that quietly mistranslates an unfamiliar construct would produce a trigger
 * that looks installed and silently fails to protect anything, which is worse than a failed
 * migration. Anything unrecognised is therefore escalated as a loud error.
 *
 * Structural differences being bridged:
 *
 * - PostgreSQL allows one trigger to fire on several events (`BEFORE UPDATE OR DELETE`). MySQL
 *   allows exactly one event per trigger, so each PostgreSQL trigger becomes several MySQL ones.
 * - PostgreSQL trigger functions branch on `TG_OP`. In MySQL the event is implicit in the trigger,
 *   so `TG_OP` comparisons are resolved at translation time instead of at runtime.
 * - PostgreSQL passes arguments to a trigger function via `TG_ARGV`. MySQL triggers take no
 *   arguments, so the argument values are baked into each generated trigger body.
 * - `RAISE EXCEPTION 'msg'` becomes `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'msg'`.
 * - `RETURN NEW` has no MySQL counterpart and is dropped; a MySQL BEFORE trigger simply returns.
 * - `to_jsonb(OLD.*) IS DISTINCT FROM to_jsonb(NEW.*)` has no MySQL equivalent, because MySQL
 *   cannot convert a whole row to JSON. It is expanded into an explicit per-column comparison
 *   using the NULL-safe `<=>` operator, which is what `IS NOT DISTINCT FROM` means.
 *
 * KNOWN DIVERGENCE, recorded rather than hidden. The PostgreSQL version reads the row dynamically
 * with `to_jsonb(NEW.*)`, so a column added to the table later is protected automatically. The MySQL
 * version has to enumerate columns when the trigger is created, so a column added afterwards is not
 * covered until the trigger is recreated. Any later migration that adds a column to a table
 * carrying one of these triggers must recreate it.
 */
class PlPgSqlTriggerTranslator
{
    /**
     * Build the MySQL trigger body for one event.
     *
     * @param  list<string>  $arguments  values the PostgreSQL trigger passed via TG_ARGV
     */
    public static function translate(
        Connection $connection,
        string $functionBody,
        string $table,
        string $event,
        array $arguments,
    ): string {
        $event = strtoupper($event);

        $normalised = self::normalise($functionBody);

        if (self::looksLikeAllowlistGuard($normalised)) {
            return self::allowlistGuard($connection, $table, $event, $arguments);
        }

        if (self::looksLikeBlanketGuard($normalised)) {
            return self::blanketGuard($normalised);
        }

        throw new RuntimeException(
            'The MySQL port does not recognise this plpgsql trigger function body and will not guess '
            . 'at a translation, because a wrong guess would leave a trigger that silently protects '
            . "nothing. Body:\n" . $functionBody
        );
    }

    /**
     * Which MySQL events a PostgreSQL `fire` clause expands into.
     *
     * @return list<string>
     */
    public static function events(string $fire): array
    {
        $fire = strtoupper($fire);

        $timing = str_contains($fire, 'AFTER') ? 'AFTER' : 'BEFORE';

        $events = [];

        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            if (str_contains($fire, $event)) {
                $events[] = "{$timing} {$event}";
            }
        }

        if ($events === []) {
            throw new RuntimeException("Could not determine any trigger event from [{$fire}].");
        }

        return $events;
    }

    private static function normalise(string $body): string
    {
        return preg_replace('/\s+/', ' ', trim($body)) ?? $body;
    }

    private static function looksLikeAllowlistGuard(string $body): bool
    {
        return str_contains($body, 'TG_ARGV')
            && str_contains($body, 'to_jsonb')
            && str_contains($body, 'IS DISTINCT FROM');
    }

    private static function looksLikeBlanketGuard(string $body): bool
    {
        return ! str_contains($body, 'TG_ARGV')
            && ! str_contains($body, 'to_jsonb')
            && preg_match('/IF\s+OLD\.([a-z0-9_]+)\s+THEN\s+RAISE EXCEPTION/i', $body) === 1;
    }

    /**
     * Translate the simple form: any modification of a flagged row is rejected.
     */
    private static function blanketGuard(string $body): string
    {
        if (preg_match('/IF\s+OLD\.([a-z0-9_]+)\s+THEN\s+RAISE EXCEPTION\s+\'(.+?)\'/i', $body, $matches) !== 1) {
            throw new RuntimeException('Expected an "IF OLD.<column> THEN RAISE EXCEPTION" guard.');
        }

        [, $column, $message] = $matches;

        return sprintf(
            'BEGIN IF OLD.%s THEN %s; END IF; END',
            self::wrap($column),
            self::signal($message),
        );
    }

    /**
     * Translate the allowlist form: a flagged row may only change in the named columns.
     *
     * @param  list<string>  $arguments
     */
    private static function allowlistGuard(Connection $connection, string $table, string $event, array $arguments): string
    {
        $flag = 'is_system_protected';

        if (str_contains($event, 'DELETE')) {
            // The PostgreSQL body rejects any delete of a protected row outright, regardless of the
            // allowlist, which only governs updates.
            return sprintf(
                'BEGIN IF OLD.%s THEN %s; END IF; END',
                self::wrap($flag),
                self::signal('Cannot delete system protected rows'),
            );
        }

        if (! str_contains($event, 'UPDATE')) {
            throw new RuntimeException("The allowlist guard does not apply to the [{$event}] event.");
        }

        $guarded = array_values(array_diff(
            self::columnsOf($connection, $table),
            $arguments,
        ));

        if ($guarded === []) {
            throw new RuntimeException(
                "Every column of [{$table}] is on the allowlist, so the generated trigger would "
                . 'protect nothing. Refusing to create it.'
            );
        }

        // `<=>` is MySQL's NULL-safe equality, so `NOT (NEW.c <=> OLD.c)` is exactly PostgreSQL's
        // `NEW.c IS DISTINCT FROM OLD.c`. Using plain `<>` here would miss changes to or from NULL.
        $changed = implode(' OR ', array_map(
            fn (string $column): string => sprintf('NOT (NEW.%1$s <=> OLD.%1$s)', self::wrap($column)),
            $guarded,
        ));

        return sprintf(
            'BEGIN IF OLD.%s AND (%s) THEN %s; END IF; END',
            self::wrap($flag),
            $changed,
            self::signal('Cannot modify system protected row columns'),
        );
    }

    /**
     * @return list<string>
     */
    private static function columnsOf(Connection $connection, string $table): array
    {
        $columns = $connection->getSchemaBuilder()->getColumnListing($table);

        if ($columns === []) {
            throw new RuntimeException("Cannot enumerate the columns of [{$table}] to build a trigger.");
        }

        return array_values($columns);
    }

    private static function signal(string $message): string
    {
        $escaped = str_replace("'", "''", $message);

        return "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$escaped}'";
    }

    private static function wrap(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
