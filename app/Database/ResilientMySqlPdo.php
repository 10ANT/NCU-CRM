<?php

declare(strict_types=1);

namespace App\Database;

use Pdo\Mysql;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * MySQL performs an implicit COMMIT before and after every DDL statement, which PostgreSQL
 * does not. So a `CREATE TABLE` inside `DB::transaction()` silently ends the transaction, and
 * Laravel's subsequent `PDO::commit()` throws "There is no active transaction" even though the
 * schema change itself succeeded and is already durable.
 *
 * That pattern is used by 77 of this project's 706 migrations, which wrap `Schema::create()`
 * calls in `DB::transaction()`. On PostgreSQL those transactions are meaningful, because
 * PostgreSQL has transactional DDL. On MySQL they cannot be, no matter what we do here, since
 * the engine has already committed.
 *
 * This class therefore makes `commit()` and `rollBack()` no-ops when the driver reports that no
 * transaction is open. It does not weaken transactional behaviour that MySQL was otherwise going
 * to provide: by the time either method is reached in that state, the engine has already decided
 * the outcome. Ordinary DML transactions are untouched and still commit and roll back normally,
 * because for those `inTransaction()` is still true.
 *
 * The alternative was editing 77 upstream migrations, which would both produce a large permanent
 * diff against upstream and remove genuine atomicity from the PostgreSQL path.
 *
 * Proven by `tests/Tenant/Database/ResilientMySqlPdoTest.php`.
 */
class ResilientMySqlPdo extends Mysql
{
    /**
     * Commit the active transaction, tolerating an implicit commit already performed by MySQL.
     */
    public function commit(): bool
    {
        if (! $this->inTransaction()) {
            return true;
        }

        return parent::commit();
    }

    /**
     * Roll back the active transaction, tolerating an implicit commit already performed by MySQL.
     *
     * A rollback requested after MySQL has implicitly committed cannot undo anything. Reporting
     * that honestly through the return value is better than throwing, because the caller's own
     * error handling then runs instead of being replaced by a confusing PDO exception.
     */
    public function rollBack(): bool
    {
        if (! $this->inTransaction()) {
            return false;
        }

        return parent::rollBack();
    }
}
