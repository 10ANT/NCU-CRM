<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Gives MySQL the cascading TRUNCATE that upstream relies on without knowing it does.
 *
 * Laravel's PostgreSQL grammar compiles `->truncate()` to
 * `TRUNCATE "table" RESTART IDENTITY CASCADE`, with cascade enabled by default
 * (`Illuminate\Database\Query\Grammars\PostgresGrammar::$cascadeTruncate = true`, line 47). CASCADE
 * does two things: it permits the truncate even though other tables hold foreign keys into this one,
 * and it empties those referencing tables as well.
 *
 * MySQL's TRUNCATE has no CASCADE and refuses outright with error 1701 whenever any foreign key
 * points at the table, even if the referencing table is already empty. That stopped
 * `app-modules/ai/database/migrations/2024_04_03_232218_remove_existing_chats.php`.
 *
 * Simply disabling foreign key checks would get past the error, but it would not reproduce the
 * behaviour: PostgreSQL leaves the referencing tables empty, so a MySQL run that left rows in them
 * would diverge and could leave orphaned rows behind. This grammar therefore resolves the same set
 * of tables CASCADE would reach, transitively, and truncates all of them.
 *
 * Foreign key checks are suspended for the duration because MySQL rejects the parent truncate on the
 * mere existence of the constraint. They are restored immediately afterwards, and every table in the
 * dependency closure is emptied, so no constraint is actually left violated.
 */
class MySqlCascadeTruncateGrammar extends MySqlGrammar
{
    /**
     * Compile a truncate table command.
     *
     * @return array<string, list<mixed>>
     */
    public function compileTruncate(Builder $query): array
    {
        $table = (string) $query->from;

        $tables = $this->dependencyClosure($table);

        $statements = ['set foreign_key_checks = 0' => []];

        // Dependents first, then the table itself, mirroring the order PostgreSQL's CASCADE implies.
        foreach ($tables as $dependent) {
            $statements['truncate table ' . $this->wrapTable($dependent)] = [];
        }

        $statements['truncate table ' . $this->wrapTable($table)] = [];
        $statements['set foreign_key_checks = 1'] = [];

        return $statements;
    }

    /**
     * Every table that transitively holds a foreign key into the given table.
     *
     * This is the set PostgreSQL's CASCADE would empty. Resolved from `information_schema` at compile
     * time, because it depends on the schema as it exists during the migration rather than on
     * anything knowable statically.
     *
     * @return list<string>
     */
    private function dependencyClosure(string $table): array
    {
        $seen = [$table => true];
        $queue = [$table];
        $dependents = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            $rows = $this->connection->select(
                'select distinct table_name as referencing_table '
                . 'from information_schema.key_column_usage '
                . 'where table_schema = database() and referenced_table_name = ?',
                [$current],
            );

            foreach ($rows as $row) {
                $referencing = (string) $row->referencing_table;

                if (isset($seen[$referencing])) {
                    continue;
                }

                $seen[$referencing] = true;
                $dependents[] = $referencing;
                $queue[] = $referencing;
            }
        }

        return $dependents;
    }
}
