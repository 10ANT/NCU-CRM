<?php

declare(strict_types=1);

namespace App\Database\Schema;

use RuntimeException;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Rewrites PostgreSQL's `expression::type` cast shorthand into the standard `CAST(expression AS type)`
 * that MySQL accepts.
 *
 * The shorthand appears throughout this project's raw SQL, including the view built by
 * `app-modules/engagement/database/migrations/2025_09_15_201808_create_holistic_engagements_view.php`
 * (`user_id::VARCHAR`) and the alerts view in `GenerateStudentAlertsView.php` (`'...'::uuid`).
 *
 * Two things make this safe rather than a blunt find and replace:
 *
 * - **String literals are skipped.** Scanning tracks whether it is inside a single-quoted literal, so
 *   a `::` occurring in stored data or in a message is never touched.
 * - **The operand is found by scanning backwards** across a balanced parenthesised expression, a
 *   quoted literal, or a qualified identifier, so `COALESCE(a, b)::text` casts the whole call and not
 *   just its final token.
 *
 * An unrecognised target type raises rather than being passed through, because a cast silently left in
 * PostgreSQL syntax would be a syntax error at best and a wrong result at worst.
 */
class PostgresCastTranslator
{
    /**
     * PostgreSQL cast targets mapped to the types MySQL's CAST accepts.
     */
    private const TYPE_MAP = [
        'text' => 'char',
        'varchar' => 'char',
        'character varying' => 'char',
        'char' => 'char',
        'uuid' => 'char(36)',
        'int' => 'signed',
        'int2' => 'signed',
        'int4' => 'signed',
        'int8' => 'signed',
        'integer' => 'signed',
        'bigint' => 'signed',
        'smallint' => 'signed',
        'numeric' => 'decimal',
        'decimal' => 'decimal',
        'real' => 'decimal',
        'float' => 'decimal',
        'double precision' => 'decimal',
        'json' => 'json',
        'jsonb' => 'json',
        'date' => 'date',
        'timestamp' => 'datetime',
        'timestamptz' => 'datetime',
        'time' => 'time',
    ];

    public static function handles(string $sql): bool
    {
        return str_contains($sql, '::');
    }

    /**
     * Rewrite every cast in a statement.
     */
    public static function translate(string $sql): string
    {
        $result = $sql;

        // Repeatedly rewrite the first remaining cast. Nested casts such as `a::text::uuid` resolve
        // naturally, because rewriting the inner one leaves the outer operand parenthesised.
        for ($guard = 0; $guard < 200; $guard++) {
            $position = self::firstCastPosition($result);

            if ($position === null) {
                return $result;
            }

            $result = self::rewriteAt($result, $position);
        }

        throw new RuntimeException('Gave up rewriting PostgreSQL casts; the statement appears malformed.');
    }

    /**
     * Offset of the first `::` that is not inside a string literal.
     */
    private static function firstCastPosition(string $sql): ?int
    {
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];

            if ($character === "'") {
                // '' inside a literal is an escaped quote, not the end of it.
                if ($inString && $i + 1 < $length && $sql[$i + 1] === "'") {
                    $i++;

                    continue;
                }

                $inString = ! $inString;

                continue;
            }

            if (! $inString && $character === ':' && $i + 1 < $length && $sql[$i + 1] === ':') {
                return $i;
            }
        }

        return null;
    }

    private static function rewriteAt(string $sql, int $position): string
    {
        $operandStart = self::operandStart($sql, $position);
        $operand = substr($sql, $operandStart, $position - $operandStart);

        [$type, $afterType] = self::readType($sql, $position + 2);

        $mysqlType = self::TYPE_MAP[strtolower($type)] ?? null;

        if ($mysqlType === null) {
            throw new RuntimeException(
                "The MySQL port has no CAST mapping for the PostgreSQL type [{$type}]. Add one rather "
                . 'than leaving PostgreSQL cast syntax in a statement MySQL will misread.'
            );
        }

        return substr($sql, 0, $operandStart)
            . 'CAST(' . trim($operand) . ' AS ' . strtoupper($mysqlType) . ')'
            . substr($sql, $afterType);
    }

    /**
     * Walk backwards from a cast to the start of the expression being cast.
     */
    private static function operandStart(string $sql, int $castPosition): int
    {
        $i = $castPosition - 1;

        while ($i >= 0 && ctype_space($sql[$i])) {
            $i--;
        }

        if ($i < 0) {
            throw new RuntimeException('Found a PostgreSQL cast with nothing to cast.');
        }

        if ($sql[$i] === ')') {
            $depth = 0;

            while ($i >= 0) {
                if ($sql[$i] === ')') {
                    $depth++;
                } elseif ($sql[$i] === '(') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }

                $i--;
            }

            // Include a function name immediately preceding the parenthesis, so that
            // `coalesce(a, b)::text` casts the call rather than only its bracketed part.
            $start = $i;

            while ($start > 0 && (ctype_alnum($sql[$start - 1]) || $sql[$start - 1] === '_' || $sql[$start - 1] === '.')) {
                $start--;
            }

            return $start;
        }

        if ($sql[$i] === "'") {
            $i--;

            while ($i >= 0) {
                if ($sql[$i] === "'") {
                    if ($i > 0 && $sql[$i - 1] === "'") {
                        $i -= 2;

                        continue;
                    }

                    return $i;
                }

                $i--;
            }

            throw new RuntimeException('Unterminated string literal before a PostgreSQL cast.');
        }

        $start = $i + 1;

        while ($start > 0) {
            $previous = $sql[$start - 1];

            if (ctype_alnum($previous) || $previous === '_' || $previous === '.' || $previous === '"' || $previous === '`') {
                $start--;

                continue;
            }

            break;
        }

        return $start;
    }

    /**
     * Read the target type following a cast operator.
     *
     * @return array{0: string, 1: int} the type, and the offset just past it
     */
    private static function readType(string $sql, int $offset): array
    {
        $length = strlen($sql);

        while ($offset < $length && ctype_space($sql[$offset])) {
            $offset++;
        }

        $start = $offset;

        while ($offset < $length && (ctype_alnum($sql[$offset]) || $sql[$offset] === '_')) {
            $offset++;
        }

        $type = substr($sql, $start, $offset - $start);

        if ($type === '') {
            throw new RuntimeException('Found a PostgreSQL cast with no target type.');
        }

        // Two-word types such as `character varying` and `double precision`.
        $lookahead = $offset;

        while ($lookahead < $length && ctype_space($sql[$lookahead])) {
            $lookahead++;
        }

        $wordStart = $lookahead;

        while ($lookahead < $length && ctype_alpha($sql[$lookahead])) {
            $lookahead++;
        }

        $second = substr($sql, $wordStart, $lookahead - $wordStart);

        if ($second !== '' && isset(self::TYPE_MAP[strtolower($type . ' ' . $second)])) {
            $type .= ' ' . $second;
            $offset = $lookahead;
        }

        // A length or precision, as in `::varchar(255)`, is discarded: MySQL's CHAR without a length
        // is unbounded for CAST purposes and preserves the value, whereas copying the length across
        // could truncate where PostgreSQL would not.
        $lookahead = $offset;

        while ($lookahead < $length && ctype_space($sql[$lookahead])) {
            $lookahead++;
        }

        if ($lookahead < $length && $sql[$lookahead] === '(') {
            $depth = 0;

            while ($lookahead < $length) {
                if ($sql[$lookahead] === '(') {
                    $depth++;
                } elseif ($sql[$lookahead] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $lookahead++;

                        break;
                    }
                }

                $lookahead++;
            }

            $offset = $lookahead;
        }

        return [$type, $offset];
    }
}
