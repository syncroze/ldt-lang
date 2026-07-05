<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * The built-in filter set, applied as a postfix pipe chain in `[= ... ]`:
 *
 *   [= @name | trim | upper]
 *   [= @items | join: ", "]
 *   [= @text | truncate: @width - 2, "…"]
 *
 * A chain entry is ['name' => string, 'args' => Expr[]]; args are full
 * expressions evaluated against the environment at apply time. A value flowing
 * through the chain is a string or an array (arrays feed join/first/last).
 * Type mismatches and unknown names throw RuntimeException — the callers
 * (Interpreter / Expr) convert that into a SyntaxError with coordinates.
 */
final class Filters
{
    /**
     * @param string|array<int|string, mixed> $value
     * @param array<int, array{name: string, args: array<int, Expr>}> $chain
     * @return string|array<int|string, mixed>
     */
    public static function apply(string|array $value, array $chain, Environment $env): string|array
    {
        foreach ($chain as $filter) {
            $name = $filter['name'];
            self::checkArity($name, count($filter['args']));

            // `default` is a fallback, so its argument evaluates lazily —
            // only when the fallback actually fires (an error in an unused
            // fallback must not surface). Falsy = the same rule `[if]` uses,
            // plus the empty array; the argument may itself be a raw array
            // reference, so a list can fall back to another list.
            if ($name === 'default') {
                $falsy = is_array($value) ? $value === [] : !Expr::truthy($value);
                if ($falsy) {
                    $value = $filter['args'][0]->evaluateRaw($env);
                }
                continue;
            }

            $args = array_map(
                static fn (Expr $e): string|array => $e->evaluateRaw($env),
                $filter['args'],
            );
            $value = self::applyOne($name, $value, $args);
        }
        return $value;
    }

    private static function checkArity(string $name, int $n): void
    {
        $arity = self::ARITY[$name] ?? throw new \RuntimeException("unknown filter '$name'");
        if ($n < $arity[0] || $n > $arity[1]) {
            throw new \RuntimeException("filter '$name' " . self::arityText($arity) . ", got $n");
        }
    }

    /** True when the chain contains a `default` filter (it handles undefined). */
    public static function chainHasDefault(array $chain): bool
    {
        foreach ($chain as $filter) {
            if ($filter['name'] === 'default') {
                return true;
            }
        }
        return false;
    }

    /** Allowed argument counts per filter: name => [min, max]. */
    private const ARITY = [
        'upper' => [0, 0], 'lower' => [0, 0], 'trim' => [0, 0],
        'capitalize' => [0, 0], 'first' => [0, 0], 'last' => [0, 0],
        'abs' => [0, 0], 'html' => [0, 0],
        'round' => [0, 1], 'join' => [0, 1],
        'truncate' => [1, 2],
        'default' => [1, 1],
    ];

    /**
     * Apply one filter ({@see checkArity} already ran). Args are evaluated
     * expressions: a plain reference reads raw, so an arg may be an array —
     * the scalar-expecting guards below reject that loudly.
     *
     * @param string|array<int|string, mixed> $value
     * @param array<int, string|array<int|string, mixed>> $args
     * @return string|array<int|string, mixed>
     */
    private static function applyOne(string $name, string|array $value, array $args): string|array
    {
        return match ($name) {
            'upper' => strtoupper(self::scalar($name, $value)),
            'lower' => strtolower(self::scalar($name, $value)),
            'trim' => trim(self::scalar($name, $value)),
            'capitalize' => ucfirst(self::scalar($name, $value)),
            'truncate' => self::truncate(self::scalar($name, $value), $args),
            'join' => self::join($name, $value, $args),
            'first' => self::edge($name, $value, first: true),
            'last' => self::edge($name, $value, first: false),
            'round' => self::round(self::scalar($name, $value), $args),
            'abs' => (string) abs(self::numeric($name, self::scalar($name, $value))),
            'html' => htmlspecialchars(self::scalar($name, $value), ENT_QUOTES),
            default => throw new \RuntimeException("unknown filter '$name'"),
        };
    }

    /** @param array{0: int, 1: int} $arity */
    private static function arityText(array $arity): string
    {
        [$min, $max] = $arity;
        return match (true) {
            $min === 0 && $max === 0 => 'takes no arguments',
            $min === $max => "takes exactly $min argument" . ($min === 1 ? '' : 's'),
            $min === 0 => "takes at most $max argument" . ($max === 1 ? '' : 's'),
            default => "takes $min to $max arguments",
        };
    }

    private static function truncate(string $value, array $args): string
    {
        $n = self::intArg('truncate', $args[0] ?? null);
        if ($n < 0) {
            throw new \RuntimeException("filter 'truncate' expects a non-negative length");
        }
        $suffix = self::strArg('truncate', $args[1] ?? '');
        if (strlen($value) <= $n) {
            return $value;
        }
        $cut = substr($value, 0, $n);

        // The length counts bytes, but a cut must never split a UTF-8
        // character: when it lands inside a multi-byte sequence, drop the
        // incomplete tail (a complete character at the edge is kept). Pure
        // byte arithmetic — no mbstring dependency.
        $len = strlen($cut);
        $cont = 0; // continuation bytes (10xxxxxx) at the tail
        while ($cont < 3 && $cont < $len && (\ord($cut[$len - 1 - $cont]) & 0xC0) === 0x80) {
            $cont++;
        }
        if ($cont < $len) {
            $lead = \ord($cut[$len - 1 - $cont]);
            $need = $lead >= 0xF0 ? 3 : ($lead >= 0xE0 ? 2 : ($lead >= 0xC0 ? 1 : -1));
            if ($need > $cont) {
                $cut = substr($cut, 0, $len - 1 - $cont); // incomplete sequence
            }
        }

        return $cut . $suffix;
    }

    /** @param string|array<int|string, mixed> $value */
    private static function join(string $name, string|array $value, array $args): string
    {
        $arr = self::arr($name, $value);
        foreach ($arr as $item) {
            if (is_array($item)) {
                throw new \RuntimeException("filter 'join' cannot join nested arrays");
            }
        }
        return implode(self::strArg($name, $args[0] ?? ''), $arr);
    }

    /**
     * @param string|array<int|string, mixed> $value
     * @return string|array<int|string, mixed>
     */
    private static function edge(string $name, string|array $value, bool $first): string|array
    {
        $arr = self::arr($name, $value);
        if ($arr === []) {
            return '';
        }
        $key = $first ? array_key_first($arr) : array_key_last($arr);
        return $arr[$key];
    }

    private static function round(string $value, array $args): string
    {
        $n = $args === [] ? 0 : self::intArg('round', $args[0]);
        return (string) round(self::numeric('round', $value), $n);
    }

    // --- input guards ----------------------------------------------------

    /** @param string|array<int|string, mixed> $value */
    private static function scalar(string $name, string|array $value): string
    {
        if (is_array($value)) {
            throw new \RuntimeException("filter '$name' cannot be applied to an array (add a join)");
        }
        return $value;
    }

    /**
     * @param string|array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private static function arr(string $name, string|array $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException("filter '$name' expects an array");
        }
        return $value;
    }

    private static function numeric(string $name, string $value): float
    {
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $value) !== 1) {
            throw new \RuntimeException("filter '$name' expects a number, got '$value'");
        }
        return (float) $value;
    }

    /** @param string|array<int|string, mixed>|null $value */
    private static function intArg(string $name, string|array|null $value): int
    {
        if (is_array($value)) {
            throw new \RuntimeException("filter '$name' expects an integer argument, got an array");
        }
        if ($value === null || preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw new \RuntimeException("filter '$name' expects an integer argument" . ($value === null ? '' : ", got '$value'"));
        }
        return (int) $value;
    }

    /** @param string|array<int|string, mixed> $value */
    private static function strArg(string $name, string|array $value): string
    {
        if (is_array($value)) {
            throw new \RuntimeException("filter '$name' argument cannot be an array");
        }
        return $value;
    }
}
