<?php

declare(strict_types=1);

namespace App\Database\Schema;

use App\Database\MySqlEnhancedBlueprint;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Supplies MySQL implementations of the `tpetry/laravel-postgresql-enhanced` schema features that
 * this project's migrations use. Laravel dispatches a queued schema command to
 * `compile<CommandName>` on the grammar and accepts a macro there just as readily as a real method
 * (`Illuminate\Database\Schema\Blueprint::toSql()` line 149), and the schema builder is `Macroable`
 * too. So the whole adapter is registered rather than subclassed, and no framework or vendor file
 * is patched.
 *
 * Counted across the 722 migration files, the enhanced surface actually used is five Blueprint
 * methods and four Schema methods. Everything registered below corresponds to one of them; nothing
 * speculative is implemented.
 */
class MySqlEnhancedSchemaMacros
{
    /**
     * Commands the package can queue that this adapter knows how to compile.
     *
     * Used by {@see MySqlEnhancedBlueprint::ensureCommandsAreValid()} to fail loudly on an enhanced
     * feature that has no MySQL translation, instead of letting Laravel skip it in silence.
     *
     * @var list<string>
     */
    public const SUPPORTED_COMMANDS = [
        'unique2',
        'dropUnique2',
        'dropUniqueIfExists',
        'dropUnique2IfExists',
        'trigger',
        'dropTriggerIfExists',
    ];

    public static function register(): void
    {
        self::registerIndexMacros();
        self::registerTriggerMacros();
        self::registerBuilderMacros();
    }

    private static function registerIndexMacros(): void
    {
        // uniqueIndex(). Plain indexes are ordinary unique keys. Partial ones, carrying a ->where(),
        // become functional unique indexes over a CASE expression, which reproduces PostgreSQL's
        // partial-index semantics because MySQL does not treat NULL key parts as duplicates.
        MySqlGrammar::macro('compileUnique2', function (Blueprint $blueprint, Fluent $command) {
            /** @var MySqlGrammar $this */
            if (blank($command['where'])) {
                return $this->compileUnique($blueprint, $command);
            }

            $condition = PartialIndexCondition::compile(
                MySqlEnhancedSchemaMacros::connectionOf($blueprint),
                $command['where'],
            );

            $keyParts = array_map(
                fn (string $column): string => sprintf('(case when %s then %s end)', $condition, $this->wrap($column)),
                (array) $command['columns'],
            );

            return sprintf(
                'alter table %s add unique %s(%s)',
                $this->wrapTable($blueprint),
                $this->wrap($command['index']),
                implode(', ', $keyParts),
            );
        });

        MySqlGrammar::macro('compileDropUnique2', function (Blueprint $blueprint, Fluent $command) {
            /** @var MySqlGrammar $this */
            return $this->compileDropUnique($blueprint, $command);
        });

        // MySQL has no DROP INDEX IF EXISTS, so existence is checked against information_schema at
        // compile time and the statement is omitted when the index is absent. Returning null tells
        // Laravel to skip the command.
        $dropIfExists = function (Blueprint $blueprint, Fluent $command) {
            /** @var MySqlGrammar $this */
            $connection = MySqlEnhancedSchemaMacros::connectionOf($blueprint);

            $exists = $connection->selectOne(
                'select 1 as found from information_schema.statistics '
                . 'where table_schema = database() and table_name = ? and index_name = ? limit 1',
                [$blueprint->getTable(), $command['index']],
            );

            if ($exists === null) {
                return null;
            }

            return $this->compileDropUnique($blueprint, $command);
        };

        MySqlGrammar::macro('compileDropUniqueIfExists', $dropIfExists);
        MySqlGrammar::macro('compileDropUnique2IfExists', $dropIfExists);
    }

    private static function registerTriggerMacros(): void
    {
        MySqlGrammar::macro('compileTrigger', function (Blueprint $blueprint, Fluent $command) {
            /** @var MySqlGrammar $this */
            $connection = MySqlEnhancedSchemaMacros::connectionOf($blueprint);

            if ($command['forEach'] === 'statement') {
                throw new RuntimeException(
                    'MySQL has no statement-level triggers, so this trigger cannot be translated '
                    . 'without changing what it does. Escalate rather than weakening it.'
                );
            }

            $trigger = (string) $command['trigger'];
            $table = $blueprint->getTable();
            $fire = (string) $command['fire'];
            $action = (string) $command['action'];

            [$function] = MySqlEnhancedSchemaMacros::parseTriggerAction($action);

            // Recorded so that a later replacement of the function rebuilds this trigger, which is how
            // PostgreSQL behaves and what MySQL's inlined trigger bodies would otherwise lose.
            PostgresTriggerRegistry::record($connection, $trigger, $table, $fire, $action, $function);

            return TriggerBuilder::statements($connection, $trigger, $table, $fire, $action);
        });

        MySqlGrammar::macro('compileDropTriggerIfExists', function (Blueprint $blueprint, Fluent $command) {
            /** @var MySqlGrammar $this */
            $trigger = (string) $command['trigger'];

            PostgresTriggerRegistry::forget(
                MySqlEnhancedSchemaMacros::connectionOf($blueprint),
                $trigger,
            );

            $statements = [];

            // One PostgreSQL trigger became several MySQL ones, so every possible suffix is dropped.
            // `if exists` makes the extras harmless.
            foreach (TriggerBuilder::possibleNames($trigger) as $name) {
                $statements[] = 'drop trigger if exists ' . $this->wrap($name);
            }

            return $statements;
        });
    }

    private static function registerBuilderMacros(): void
    {
        // PostgreSQL trigger functions have no MySQL counterpart, so the body is recorded for the
        // trigger compiler to inline rather than being executed here. The capability is preserved;
        // only where the code lives changes.
        SchemaBuilder::macro('createFunctionOrReplace', function (
            string $name,
            array $parameters,
            array|string $return,
            string $language,
            string $body,
            array $options = [],
        ): void {
            /** @var SchemaBuilder $this */
            $connection = $this->getConnection();

            PostgresFunctionRegistry::record($connection, $name, $body, $language);

            // PostgreSQL resolves a trigger's function at fire time, so replacing the function changes
            // every trigger using it. MySQL inlines the body, so the triggers are rebuilt here instead.
            PostgresTriggerRegistry::rebuildFor($connection, $name);
        });

        SchemaBuilder::macro('dropFunctionIfExists', function (string $name, ?array $arguments = null): void {
            /** @var SchemaBuilder $this */
            PostgresFunctionRegistry::forget($this->getConnection(), $name);
        });

        $createView = function (string $name, QueryBuilder|string $query, array $columns = [], bool $replace = false): void {
            /** @var SchemaBuilder $this */
            $connection = $this->getConnection();
            $sql = $query instanceof QueryBuilder ? $query->toRawSql() : $query;
            $wrapped = $connection->getQueryGrammar()->wrapTable($name);

            $columnList = $columns === []
                ? ''
                : ' (' . implode(', ', array_map(
                    fn (string $column): string => $connection->getQueryGrammar()->wrap($column),
                    $columns,
                )) . ')';

            // MySQL's CREATE OR REPLACE VIEW is atomic, unlike a drop followed by a create, so it is
            // preferred whenever a replace is what was asked for.
            $verb = $replace ? 'create or replace view' : 'create view';

            $connection->statement("{$verb} {$wrapped}{$columnList} as {$sql}");
        };

        SchemaBuilder::macro('createView', function (string $name, QueryBuilder|string $query, array $columns = []) use ($createView): void {
            /** @var SchemaBuilder $this */
            $createView->call($this, $name, $query, $columns, false);
        });

        SchemaBuilder::macro('createViewOrReplace', function (string $name, QueryBuilder|string $query, array $columns = []) use ($createView): void {
            /** @var SchemaBuilder $this */
            $createView->call($this, $name, $query, $columns, true);
        });

        SchemaBuilder::macro('dropView', function (string ...$name): void {
            /** @var SchemaBuilder $this */
            $connection = $this->getConnection();
            $views = implode(', ', array_map(fn (string $view): string => $connection->getQueryGrammar()->wrapTable($view), $name));

            $connection->statement("drop view {$views}");
        });

        SchemaBuilder::macro('dropViewIfExists', function (string ...$name): void {
            /** @var SchemaBuilder $this */
            $connection = $this->getConnection();
            $views = implode(', ', array_map(fn (string $view): string => $connection->getQueryGrammar()->wrapTable($view), $name));

            $connection->statement("drop view if exists {$views}");
        });
    }

    /**
     * Resolve the connection a blueprint belongs to.
     *
     * Tenant and landlord migrations run on different connections, so the compilers must never
     * reach for a default connection when they query `information_schema` or read a recorded
     * function body.
     */
    public static function connectionOf(Blueprint $blueprint): \Illuminate\Database\Connection
    {
        if (! $blueprint instanceof MySqlEnhancedBlueprint) {
            throw new RuntimeException(
                'An enhanced schema command was compiled against ' . $blueprint::class . ', which does '
                . 'not expose its connection. The MySQL port expects ' . MySqlEnhancedBlueprint::class . '.'
            );
        }

        return $blueprint->getPortConnection();
    }

    /**
     * Split a PostgreSQL trigger action into its function name and literal arguments.
     *
     * For example `prevent_modification_of_system_protected_rows('sort', 'updated_at')` yields the
     * function name plus `['sort', 'updated_at']`. MySQL triggers accept no arguments, so these are
     * resolved when the trigger is built.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function parseTriggerAction(string $action): array
    {
        if (preg_match('/^\s*([a-zA-Z0-9_."]+)\s*\((.*)\)\s*$/s', $action, $matches) !== 1) {
            throw new RuntimeException("Could not parse the trigger action [{$action}].");
        }

        $name = trim($matches[1], '"');
        $arguments = [];

        if (trim($matches[2]) !== '') {
            preg_match_all("/'((?:[^']|'')*)'/", $matches[2], $found);

            $arguments = array_map(
                static fn (string $argument): string => str_replace("''", "'", $argument),
                $found[1],
            );
        }

        return [$name, $arguments];
    }
}
