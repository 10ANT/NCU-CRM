<?php

declare(strict_types=1);

namespace App\Database\Schema;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Turns the `->where(...)` closure of a PostgreSQL partial index into a MySQL boolean expression.
 *
 * 15 migrations declare partial unique indexes, for example:
 *
 *     $table->uniqueIndex('domain', 'tenants_domain_unique')
 *         ->where(fn (Builder $condition) => $condition->whereNull('deleted_at'));
 *
 * which on PostgreSQL means "domain is unique only among rows that are not soft-deleted", so a
 * domain becomes reusable once the row holding it is deleted. MySQL has no partial indexes, but it
 * has functional key parts (8.0.13+) and it does not treat NULLs as duplicates, so a unique index
 * over `CASE WHEN <condition> THEN <column> END` enforces exactly the same rule. Rows failing the
 * condition index as NULL and never collide. Verified against MySQL 8.4.11 before this was written.
 *
 * The condition SQL itself is produced by running the closure against a real query builder on the
 * target connection, so MySQL's own grammar writes it, then trimming the `select * where` that
 * Laravel prefixes to a builder with no table.
 */
class PartialIndexCondition
{
    /**
     * Compile a partial index condition into a MySQL boolean expression.
     *
     * The package accepts either a closure or a ready-made SQL string. A string is already a
     * condition and is passed through, still subject to the boolean-literal normalisation below.
     */
    public static function compile(Connection $connection, string|Closure $condition): string
    {
        if (is_string($condition)) {
            return self::normaliseBooleanLiterals(trim($condition));
        }

        $query = $condition($connection->query());

        // toRawSql() substitutes the bindings using the connection's own grammar, which matters
        // because the result is embedded in DDL where placeholders are not allowed.
        $sql = $query->toRawSql();

        foreach (['select * where ', 'select * ', 'where '] as $prefix) {
            if (str_starts_with($sql, $prefix)) {
                $sql = substr($sql, strlen($prefix));
            }
        }

        $sql = trim($sql);

        if ($sql === '') {
            throw new RuntimeException('A partial index condition compiled to an empty expression.');
        }

        return self::normaliseBooleanLiterals($sql);
    }

    /**
     * Rewrite quoted boolean literals to MySQL's integer booleans.
     *
     * This is not cosmetic, it is a correctness fix for a silent failure. PostgreSQL only accepts
     * `column = 'true'` when the column is genuinely boolean, coercing the literal on the way in.
     * MySQL stores booleans as TINYINT(1) and coerces the other direction, casting the string
     * 'true' to 0. So `is_default = 'true'` selects precisely the rows PostgreSQL would exclude.
     *
     * Measured on MySQL 8.4.11 against rows (1, is_default=1) and (2, is_default=0):
     * `WHERE is_default = 'true'` returned row 2, while `WHERE is_default = 1` returned row 1.
     *
     * Because PostgreSQL would have rejected the comparison outright if the column were not
     * boolean, any such literal reaching this point is known to be a boolean, which makes the
     * rewrite safe rather than a guess.
     *
     * Reached by `care_team_roles`, whose partial index condition is
     * `whereNull('deleted_at')->where('is_default', 'true')`.
     */
    public static function normalise(string $sql): string
    {
        return self::normaliseBooleanLiterals($sql);
    }

    private static function normaliseBooleanLiterals(string $sql): string
    {
        return preg_replace_callback(
            "/(=|<>|!=)\s*'(true|false)'/i",
            fn (array $matches): string => $matches[1] . ' ' . (strtolower($matches[2]) === 'true' ? '1' : '0'),
            $sql,
        ) ?? $sql;
    }
}
