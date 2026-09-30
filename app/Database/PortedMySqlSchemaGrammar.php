<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Closes the gaps where MySQL's DDL requires an explicit step that PostgreSQL performs implicitly.
 * Doing it here rather than in migrations means the behaviour applies to all 706 of them at once and
 * leaves no diff against upstream to re-apply on every future merge.
 */
class PortedMySqlSchemaGrammar extends MySqlGrammar
{
    /**
     * A MySQL expression producing a random RFC 4122 version 4 UUID.
     *
     * MySQL 8 has no equivalent of PostgreSQL's `gen_random_uuid()`. Its built-in `UUID()` returns a
     * version 1 UUID, which embeds the host MAC address and a timestamp and is therefore largely
     * predictable. These ids are used as primary keys and appear in URLs, so substituting a
     * predictable generator for a random one would quietly weaken the application: it would invite
     * record enumeration that upstream does not permit.
     *
     * This builds a real version 4 UUID instead, from `RANDOM_BYTES`, with the version nibble pinned
     * to 4 and the variant nibble to one of 8, 9, a or b. Verified on MySQL 8.4.11: 36 characters,
     * version 4, correct variant, and 2000 out of 2000 distinct over a bulk insert.
     */
    private const UUID_V4_EXPRESSION = "lower(concat("
        . "hex(random_bytes(4)), '-', "
        . "hex(random_bytes(2)), '-4', "
        . "substr(hex(random_bytes(2)), 2), '-', "
        . "substr(hex(floor(128 + (rand() * 64))), 1, 2), substr(hex(random_bytes(2)), 3), '-', "
        . "hex(random_bytes(6))))";

    /**
     * Compile an add column command, splitting generated-UUID columns into steps MySQL allows.
     *
     * Three migrations add a UUID primary key to an existing table in a single statement, relying on
     * PostgreSQL evaluating `gen_random_uuid()` once per existing row, for example
     * `app-modules/student-data-model/database/migrations/2024_10_21_165912_add_id_column_to_enrollments_table.php`.
     *
     * MySQL refuses that outright with error 1674, and it does so even with binary logging disabled,
     * which was verified on this instance (`@@log_bin` is 0). A column default that is not
     * deterministic cannot be used to populate existing rows at all.
     *
     * The same end state is reached in three steps instead, which was proven against MySQL 8.4.11
     * before being written here:
     *
     *   1. add the column as nullable with no default, which MySQL permits,
     *   2. populate every existing row with a distinct version 4 UUID through an UPDATE, where a
     *      non-deterministic expression is allowed,
     *   3. make the column NOT NULL and attach the ongoing default.
     *
     * The primary key is added by the separate `primary` command Laravel queues afterwards, so the
     * ordering works out.
     *
     * KNOWN DIVERGENCE, recorded rather than hidden. Step 3's ongoing default has to be MySQL's
     * built-in `UUID()`, because that is the only generator MySQL accepts in a column default. It
     * produces a version 1 UUID, which encodes a timestamp and the host MAC address, whereas
     * PostgreSQL's `gen_random_uuid()` produces a random version 4. Existing rows backfilled in step
     * 2 do get genuine version 4 values. Rows inserted later without an explicit id get version 1,
     * which is still unique and still 36 characters, but is more predictable than upstream's. It
     * matters because these ids are primary keys that appear in URLs. Closing the gap needs a BEFORE
     * INSERT trigger per table, or the affected models to generate ids in PHP as most models in this
     * codebase already do via `HasVersion4Uuids`. Escalated in PORT_LOG.md rather than papered over.
     *
     * @return list<string>
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): array
    {
        $generated = array_values(array_filter(
            $blueprint->getAddedColumns(),
            fn (Fluent $column): bool => $this->isGeneratedUuidDefault($column['default'] ?? null),
        ));

        if ($generated === []) {
            return (array) parent::compileAdd($blueprint, $command);
        }

        if (count($generated) !== count($blueprint->getAddedColumns())) {
            throw new RuntimeException(
                'This add-column statement mixes a generated-UUID column with ordinary columns. The '
                . 'MySQL port splits generated-UUID columns into several statements and will not guess '
                . 'at how to interleave the rest. Split the migration instead.'
            );
        }

        $table = $this->wrapTable($blueprint);
        $statements = [];

        foreach ($generated as $column) {
            $name = $this->wrap($column['name']);
            $type = $this->getType($column);

            $statements[] = "alter table {$table} add {$name} {$type} null";
            $statements[] = "update {$table} set {$name} = " . self::UUID_V4_EXPRESSION . " where {$name} is null";
            $statements[] = "alter table {$table} modify {$name} {$type} not null default (uuid())";
        }

        return $statements;
    }

    /**
     * Whether a column default is a PostgreSQL generated-UUID expression.
     */
    private function isGeneratedUuidDefault(mixed $default): bool
    {
        return $default !== null
            && $default instanceof Expression
            && str_contains((string) $this->getValue($default), 'gen_random_uuid()');
    }

    /**
     * Render a column default, translating PostgreSQL-only functions.
     *
     * Six migrations declare `->default(DB::raw('gen_random_uuid()'))`, which MySQL rejects outright
     * as a syntax error. MySQL does support expression defaults since 8.0.13, but they must be
     * wrapped in parentheses, so the translation supplies those as well.
     *
     * Translating here rather than in the six migrations keeps the upstream files untouched, and
     * covers any future migration that arrives with the same expression after an upstream merge.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function getDefaultValue($value)
    {
        $default = parent::getDefaultValue($value);

        if (! str_contains($default, 'gen_random_uuid()')) {
            return $default;
        }

        return '(' . str_replace('gen_random_uuid()', self::UUID_V4_EXPRESSION, $default) . ')';
    }

    /**
     * Compile a drop unique command, first ensuring MySQL's foreign keys keep an index to rely on.
     *
     * MySQL requires every foreign key to be covered by an index and refuses to drop the last index
     * that covers one, with error 1553. PostgreSQL has no such requirement, so upstream migrations drop
     * and recreate unique indexes freely. Hit by the 20 citext conversions, for example
     * `app-modules/ai/database/migrations/2026_08_05_175003_convert_customer_advisor_categories_name_to_citext.php`,
     * which drops `qna_advisor_categories_qna_advisor_id_name_unique` while a foreign key on
     * `customer_advisor_id` depends on it.
     *
     * These migrations cannot be skipped: besides the citext conversion, which is a no-op on MySQL, they
     * replace a plain unique index with a partial one so that soft-deleted rows stop blocking name
     * reuse. That is a real behaviour change and has to happen.
     *
     * The replacement index is a functional one over a CASE expression, which MySQL will not accept as
     * foreign key cover. So a plain index on the foreign key's own columns is created first and left in
     * place. That adds an index MySQL requires and PostgreSQL does not; it removes nothing.
     *
     * @return list<string>
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): array
    {
        return array_merge(
            $this->foreignKeyCoverFor($blueprint, (string) $command['index']),
            [(string) parent::compileDropUnique($blueprint, $command)],
        );
    }

    /**
     * @return list<string>
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): array
    {
        return array_merge(
            $this->foreignKeyCoverFor($blueprint, (string) $command['index']),
            [(string) parent::compileDropIndex($blueprint, $command)],
        );
    }

    /**
     * Statements creating replacement cover for any foreign key that only the doomed index supports.
     *
     * @return list<string>
     */
    private function foreignKeyCoverFor(Blueprint $blueprint, string $indexBeingDropped): array
    {
        $table = $blueprint->getTable();

        $foreignKeys = $this->connection->select(
            'select kcu.constraint_name as fk_name, kcu.column_name as column_name '
            . 'from information_schema.key_column_usage kcu '
            . 'join information_schema.referential_constraints rc '
            . '  on rc.constraint_schema = kcu.constraint_schema '
            . ' and rc.constraint_name = kcu.constraint_name '
            . 'where kcu.table_schema = database() and kcu.table_name = ? '
            . 'order by kcu.constraint_name, kcu.ordinal_position',
            [$table],
        );

        if ($foreignKeys === []) {
            return [];
        }

        $columnsByConstraint = [];

        foreach ($foreignKeys as $row) {
            $columnsByConstraint[(string) $row->fk_name][] = (string) $row->column_name;
        }

        $statements = [];

        foreach ($columnsByConstraint as $constraint => $columns) {
            if ($this->hasOtherCoveringIndex($table, $indexBeingDropped, $columns[0])) {
                continue;
            }

            $name = substr($constraint . '_fkidx', 0, 63);

            $wrapped = implode(', ', array_map(fn (string $column): string => $this->wrap($column), $columns));

            $statements[] = sprintf(
                'alter table %s add index %s (%s)',
                $this->wrapTable($blueprint),
                $this->wrap($name),
                $wrapped,
            );
        }

        return $statements;
    }

    /**
     * Whether an index other than the one being dropped already leads with the given column.
     *
     * MySQL accepts any index whose first column is the foreign key's first column as cover, so only
     * the leading column needs checking.
     */
    private function hasOtherCoveringIndex(string $table, string $indexBeingDropped, string $leadingColumn): bool
    {
        return $this->connection->selectOne(
            'select index_name as index_name from information_schema.statistics '
            . 'where table_schema = database() and table_name = ? and column_name = ? '
            . 'and seq_in_index = 1 and index_name <> ? limit 1',
            [$table, $leadingColumn, $indexBeingDropped],
        ) !== null;
    }

    /**
     * Compile a foreign key, reconciling `ON DELETE SET NULL` on a NOT NULL column.
     *
     * `app-modules/meeting-center/database/migrations/2026_01_15_103842_add_created_by_and_other_columns_in_events_table.php`
     * declares `$table->foreignUuid('created_by_id')->constrained('users')->nullOnDelete()` without
     * `->nullable()`, so the column is NOT NULL while its foreign key promises to set it to NULL.
     *
     * PostgreSQL accepts that definition, but the action it describes can never succeed: deleting a
     * referenced user raises a not-null violation and the delete is refused. MySQL judges the same
     * definition at creation time and rejects it outright with error 1830.
     *
     * `ON DELETE RESTRICT` is used instead, which keeps the column NOT NULL and keeps the observable
     * behaviour: the delete is still refused. Only the error differs, a foreign key violation rather
     * than a not-null violation. The alternative, quietly making the column nullable, would be worse:
     * the delete would then succeed on MySQL where upstream refuses it, which is a real behaviour
     * change rather than a difference in error text.
     *
     * This looks like an upstream oversight and is worth reporting to Canyon GBS separately, since the
     * declared intent on PostgreSQL is unreachable there too.
     *
     * @return string
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command)
    {
        $sql = (string) parent::compileForeign($blueprint, $command);

        if (stripos($sql, 'on delete set null') === false) {
            return $sql;
        }

        if ($this->anyColumnIsNullable($blueprint, (array) $command['columns'])) {
            return $sql;
        }

        return str_ireplace('on delete set null', 'on delete restrict', $sql);
    }

    /**
     * Whether any of the named columns accepts NULL.
     *
     * Columns being created in this same blueprint are inspected directly, because they are not in
     * `information_schema` yet. Pre-existing columns are looked up there.
     *
     * @param  list<string>  $columns
     */
    private function anyColumnIsNullable(Blueprint $blueprint, array $columns): bool
    {
        $pending = [];

        foreach ($blueprint->getAddedColumns() as $added) {
            $pending[(string) $added['name']] = (bool) ($added['nullable'] ?? false);
        }

        foreach ($columns as $column) {
            if (array_key_exists($column, $pending)) {
                if ($pending[$column]) {
                    return true;
                }

                continue;
            }

            $existing = $this->connection->selectOne(
                'select is_nullable as is_nullable from information_schema.columns '
                . 'where table_schema = database() and table_name = ? and column_name = ? limit 1',
                [$blueprint->getTable(), $column],
            );

            // An unknown column is treated as nullable so that the original statement is emitted
            // unchanged and MySQL, not this guess, decides whether it is valid.
            if ($existing === null || strtoupper((string) $existing->is_nullable) === 'YES') {
                return true;
            }
        }

        return false;
    }

    /**
     * Compile a drop column command, first releasing any foreign keys on those columns.
     *
     * PostgreSQL's `ALTER TABLE ... DROP COLUMN` silently drops the constraints that depend on the
     * column, so upstream migrations drop a column and say nothing about its foreign key. MySQL
     * refuses with error 1828, as hit by
     * `app-modules/prospect/database/migrations/2024_05_23_173131_removed_assigned_to_id_column_from_prospects_table.php`
     * on `prospects.assigned_to_id`.
     *
     * Emitting the constraint drops first reproduces PostgreSQL's outcome exactly: the column and its
     * foreign keys are both gone afterwards. Nothing extra is removed, because only constraints that
     * actually involve the dropped columns are selected.
     *
     * @return list<string>
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): array
    {
        $statements = [];

        // A migration may already drop the foreign key itself, alongside dropping the column. All of a
        // blueprint's statements are compiled before any of them run, so information_schema still
        // reports the key as present here. Emitting our own drop as well would leave one of the two
        // statements trying to drop a key that is already gone, which MySQL reports as error 1091.
        $alreadyDropped = [];

        foreach ($blueprint->getCommands() as $queued) {
            if ($queued['name'] === 'dropForeign') {
                foreach ((array) $queued['index'] as $index) {
                    $alreadyDropped[$index] = true;
                }
            }
        }

        foreach ($this->foreignKeysOn($blueprint->getTable(), (array) $command['columns']) as $constraint) {
            if (isset($alreadyDropped[$constraint])) {
                continue;
            }

            $statements[] = sprintf(
                'alter table %s drop foreign key %s',
                $this->wrapTable($blueprint),
                $this->wrap($constraint),
            );
        }

        return array_merge($statements, (array) parent::compileDropColumn($blueprint, $command));
    }

    /**
     * Names of the foreign key constraints defined on any of the given columns.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function foreignKeysOn(string $table, array $columns): array
    {
        if ($columns === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $rows = $this->connection->select(
            'select distinct constraint_name as fk_name from information_schema.key_column_usage '
            . 'where table_schema = database() and table_name = ? '
            . "and column_name in ({$placeholders}) and referenced_table_name is not null",
            array_merge([$table], array_values($columns)),
        );

        return array_map(static fn (object $row): string => (string) $row->fk_name, $rows);
    }
}
