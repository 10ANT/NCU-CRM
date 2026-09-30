<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Schema\AlterColumnTypeTranslator;
use App\Database\Schema\DropConstraintTranslator;
use App\Database\Schema\DropIndexTranslator;
use App\Database\Schema\PartialIndexSqlTranslator;
use App\Database\Schema\PostgresCastTranslator;
use App\Database\Schema\RenameConstraintTranslator;
use Illuminate\Database\MySqlConnection;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * The MySQL connection this application uses. It exists to swap in query and schema behaviour that
 * upstream gets from PostgreSQL, in the one place Laravel offers for that: `Connection::resolverFor`.
 *
 * It supplies {@see MySqlCascadeTruncateGrammar}, which reproduces PostgreSQL's cascading TRUNCATE,
 * and {@see PortedMySqlSchemaGrammar}, which supplies the DDL steps MySQL needs spelled out where
 * PostgreSQL performs them implicitly. Anything else needing a grammar-level override belongs here
 * too, rather than being scattered through migrations.
 */
class PortedMySqlConnection extends MySqlConnection
{
    protected function getDefaultQueryGrammar(): MySqlCascadeTruncateGrammar
    {
        return new MySqlCascadeTruncateGrammar($this);
    }

    protected function getDefaultSchemaGrammar(): PortedMySqlSchemaGrammar
    {
        return new PortedMySqlSchemaGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * Supplies {@see PortedMySqlSchemaBuilder}, whose `dropAllTables()` also removes views, matching
     * PostgreSQL's cascading drop. Tenant provisioning depends on it.
     */
    public function getSchemaBuilder(): PortedMySqlSchemaBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new PortedMySqlSchemaBuilder($this);
    }

    /**
     * Execute an SQL statement, translating PostgreSQL-only DDL that no grammar can intercept.
     *
     * Four migrations issue 104 raw `ALTER TABLE ... RENAME CONSTRAINT` statements. Raw SQL never
     * passes through a schema grammar, so this is the only place the translation can be applied
     * centrally instead of by rewriting those upstream files. See {@see RenameConstraintTranslator}
     * for what each rename becomes and why.
     *
     * The guard is a single case-insensitive substring test, so ordinary statements are unaffected.
     *
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        // PostgreSQL's `expr::type` cast shorthand can appear in any raw statement, so it is
        // normalised first and the more specific translators below then see standard SQL.
        if (PostgresCastTranslator::handles($query)) {
            $query = PostgresCastTranslator::translate($query);
        }

        if ($bindings === []) {
            if (RenameConstraintTranslator::handles($query)) {
                return $this->runTranslated(RenameConstraintTranslator::translate($this, $query));
            }

            if (PartialIndexSqlTranslator::handles($query)) {
                return $this->runTranslated(PartialIndexSqlTranslator::translate($query));
            }

            if (AlterColumnTypeTranslator::handles($query)) {
                return $this->runTranslated(AlterColumnTypeTranslator::translate($this, $query));
            }

            if (DropIndexTranslator::handles($query)) {
                return $this->runTranslated(DropIndexTranslator::translate($this, $query));
            }

            if (DropConstraintTranslator::handles($query)) {
                return $this->runTranslated(DropConstraintTranslator::translate($this, $query));
            }
        }

        return parent::statement($query, $bindings);
    }

    /**
     * Run a raw, unprepared statement, applying the same PostgreSQL cast normalisation.
     *
     * @param  string  $query
     */
    public function unprepared($query): bool
    {
        if (PostgresCastTranslator::handles($query)) {
            $query = PostgresCastTranslator::translate($query);
        }

        return parent::unprepared($query);
    }

    /**
     * Run the statements a translator produced, reporting success only if all of them succeeded.
     *
     * An empty list means the statement was a genuine no-op on MySQL, which counts as success.
     *
     * @param  list<string>  $statements
     */
    private function runTranslated(array $statements): bool
    {
        $result = true;

        foreach ($statements as $statement) {
            $result = parent::statement($statement) && $result;
        }

        return $result;
    }
}
