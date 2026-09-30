<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Connection;
use RuntimeException;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint as PostgresEnhancedBlueprint;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * 227 of this project's 706 migrations type-hint their schema closure against
 * `Tpetry\PostgresqlEnhanced\Schema\Blueprint`. That package installs its Blueprint through
 * `Connection::resolverFor('pgsql', ...)`, so on a MySQL connection Laravel supplies its own
 * Blueprint instead and every one of those migrations fails on the argument type before a single
 * statement runs.
 *
 * Extending the package's Blueprint satisfies all 227 type hints without editing a migration.
 * The package's constructor only reads the connection, table name and grammar, with nothing
 * PostgreSQL-specific in it (`vendor/tpetry/laravel-postgresql-enhanced/src/Schema/Blueprint.php`),
 * so it composes correctly with a MySQL grammar.
 *
 * The enhanced methods the migrations actually call queue commands named `unique2`, `dropUnique2`,
 * `dropUniqueIfExists`, `dropUnique2IfExists`, `trigger` and `dropTriggerIfExists`. Those are
 * compiled to MySQL by the grammar macros in {@see \App\Database\Schema\MySqlEnhancedSchemaMacros}. Any other enhanced
 * method would stay uncompiled and therefore silently do nothing, which is why
 * {@see self::ensureCommandsAreValid()} is overridden below: an untranslatable schema feature must
 * fail loudly rather than be skipped in silence.
 */
class MySqlEnhancedBlueprint extends PostgresEnhancedBlueprint
{
    /**
     * Expose the connection the blueprint was built for.
     *
     * Neither Laravel's Blueprint nor the package's offers a getter, but the grammar macros need
     * the connection in order to query `information_schema` while compiling conditional drops.
     */
    public function getPortConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @return array<int, \Illuminate\Support\Fluent<string, mixed>>
     */
    public function getPortCommands(): array
    {
        return $this->commands;
    }

    /**
     * PostgreSQL's maximum identifier length, `NAMEDATALEN - 1`.
     *
     * MySQL's own limit is 64, one character longer, so truncating to PostgreSQL's 63 satisfies both
     * and produces the same names upstream produces. See {@see self::createIndexName()}.
     */
    private const POSTGRES_IDENTIFIER_LIMIT = 63;

    /**
     * Generate an index name the way upstream's PostgreSQL does, including its truncation.
     *
     * Laravel derives index and foreign key names from the table and column names, which in this
     * schema regularly exceeds both engines' limits. For example
     * `service_request_form_authentications_service_request_form_id_foreign` is 68 characters.
     *
     * The engines disagree on what to do about that. PostgreSQL truncates the identifier to 63 bytes
     * and carries on with only a notice, so upstream never had to care. MySQL rejects anything over
     * 64 outright with error 1059, which is what stopped the tenant migrations.
     *
     * Truncating to PostgreSQL's 63 rather than MySQL's 64 is deliberate. It means every generated
     * name on MySQL is byte-for-byte the name upstream already has on PostgreSQL, so a later
     * migration that drops one of these indexes by its generated name finds the same name here, and
     * any truncation collision is exactly the collision upstream would also have. Truncating to 64
     * would have produced names that agree with neither.
     *
     * Applies to foreign keys as well as indexes, because Laravel routes both through this method.
     *
     * @param  string  $type
     * @param  array<int, string>  $columns
     */
    protected function createIndexName($type, array $columns): string
    {
        $name = parent::createIndexName($type, $columns);

        if (strlen($name) <= self::POSTGRES_IDENTIFIER_LIMIT) {
            return $name;
        }

        return substr($name, 0, self::POSTGRES_IDENTIFIER_LIMIT);
    }

    /**
     * Reject any schema command this port cannot compile.
     *
     * Laravel skips a queued command whose `compile*` method is missing from the grammar
     * (`Illuminate\Database\Schema\Blueprint::toSql()` line 149), silently and without error. For an
     * ordinary Laravel command that never happens, but the PostgreSQL-enhanced package can queue
     * commands for features MySQL has no answer for, and skipping one of those would drop a
     * constraint, index or trigger while the migration still reported success.
     *
     * That is precisely the invisible feature loss this port is forbidden from introducing, so an
     * uncompilable command becomes a loud failure to be escalated and handled properly.
     *
     * The check asks the grammar the same question `toSql()` asks, rather than comparing against a
     * hand-maintained list of command names, so it cannot drift out of date as Laravel changes.
     */
    protected function ensureCommandsAreValid(): void
    {
        parent::ensureCommandsAreValid();

        $uncompilable = [];

        foreach ($this->commands as $command) {
            if ($command->shouldBeSkipped) {
                continue;
            }

            $method = 'compile' . ucfirst((string) $command['name']);

            if (method_exists($this->grammar, $method) || $this->grammar::hasMacro($method)) {
                continue;
            }

            $uncompilable[] = (string) $command['name'];
        }

        if ($uncompilable !== []) {
            throw new RuntimeException(sprintf(
                'The MySQL port has no translation for the schema command(s) [%s] queued on table [%s]. '
                . 'Laravel would skip them silently, which would drop a schema guarantee while still '
                . 'reporting the migration as successful. Escalate this rather than letting it pass.',
                implode(', ', array_unique($uncompilable)),
                $this->table,
            ));
        }
    }
}
