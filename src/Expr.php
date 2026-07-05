<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * An expression node used inside `[if]` / `[elseif]` conditions and `[= ...]`
 * emit tags.
 *
 * The expression grammar: variable reads `@path`, string/number literals,
 * `defined @path`, comparisons (== != < > <= >=), and boolean logic
 * (and / or / not) with parentheses.
 *
 * A single value class with a `kind` discriminant keeps the AST in one file
 * (PSR-4 friendly) while {@see self::evaluate()} walks it. Semantics — the
 * two-type (String/Number) model, falsy rules and numeric-aware comparison —
 * are described in full in docs/index.html (the "Caveats" section).
 */
final class Expr
{
    public const REF = 'ref';         // @path
    public const LIT = 'lit';         // bare word or "quoted" literal
    public const DEFINED = 'defined'; // defined @path
    public const COUNT = 'count';     // count @path  (array length)
    public const NOT = 'not';         // not <expr>
    public const LOGICAL = 'logical'; // <expr> and/or <expr>
    public const COMPARE = 'compare'; // <expr> ==|!=|<|>|<=|>= <expr>
    public const ARITH = 'arith';     // <expr> +|-|*|/|% <expr>  (integers)
    public const NEG = 'neg';         // - <expr>                 (unary minus)
    public const FILTERED = 'filtered'; // <expr> | filter: args...  (pipe chain)

    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly string $kind,
        public readonly array $data,
    ) {
    }

    /** @param array<int, string> $segments */
    public static function ref(array $segments): self
    {
        return new self(self::REF, ['segments' => $segments]);
    }

    public static function lit(string $value): self
    {
        return new self(self::LIT, ['value' => $value]);
    }

    /** @param array<int, string> $segments */
    public static function defined(array $segments): self
    {
        return new self(self::DEFINED, ['segments' => $segments]);
    }

    /** @param array<int, string> $segments */
    public static function count(array $segments): self
    {
        return new self(self::COUNT, ['segments' => $segments]);
    }

    public static function not(self $operand): self
    {
        return new self(self::NOT, ['operand' => $operand]);
    }

    public static function logical(string $op, self $left, self $right): self
    {
        return new self(self::LOGICAL, ['op' => $op, 'left' => $left, 'right' => $right]);
    }

    public static function compare(string $op, self $left, self $right): self
    {
        return new self(self::COMPARE, ['op' => $op, 'left' => $left, 'right' => $right]);
    }

    public static function arith(string $op, self $left, self $right): self
    {
        return new self(self::ARITH, ['op' => $op, 'left' => $left, 'right' => $right]);
    }

    public static function neg(self $operand): self
    {
        return new self(self::NEG, ['operand' => $operand]);
    }

    /** @param array<int, array{name: string, args: array<int, self>}> $chain */
    public static function filtered(self $inner, array $chain): self
    {
        return new self(self::FILTERED, ['inner' => $inner, 'chain' => $chain]);
    }

    /** Evaluate against the environment. Returns a string (ref/lit) or bool. */
    public function evaluate(Environment $env): bool|string
    {
        return match ($this->kind) {
            self::REF => $env->lookup($this->data['segments']) ?? '',
            self::LIT => $this->data['value'],
            self::DEFINED => $env->defined($this->data['segments']),
            self::COUNT => $this->evalCount($env),
            self::NOT => !$this->data['operand']->truthyIn($env),
            self::LOGICAL => $this->evalLogical($env),
            self::COMPARE => $this->evalCompare($env),
            self::ARITH => $this->evalArith($env),
            self::NEG => (string) (-$this->intOf($this->data['operand']->evaluate($env))),
            self::FILTERED => $this->evalFiltered($env),
            default => false,
        };
    }

    /**
     * Evaluate for a filter position — the chain input or a filter argument.
     * A plain reference reads raw so arrays flow (to join/first/last, or as a
     * `default:` fallback); any other expression yields a scalar.
     *
     * @return string|array<int|string, mixed>
     */
    public function evaluateRaw(Environment $env): string|array
    {
        if ($this->kind === self::REF) {
            return $env->lookupRaw($this->data['segments']) ?? '';
        }
        return self::toStr($this->evaluate($env));
    }

    /**
     * Run the inner value through the filter chain. The chain's final result
     * must be a scalar to be emitted.
     */
    private function evalFiltered(Environment $env): string
    {
        $input = $this->data['inner']->evaluateRaw($env);
        $result = Filters::apply($input, $this->data['chain'], $env);
        if (is_array($result)) {
            throw new \RuntimeException('filter result is an array (add a join)');
        }
        return $result;
    }

    /** Render an evaluated value as output text (a bool becomes "1" / ""). */
    public static function render(bool|string $value): string
    {
        return self::toStr($value);
    }

    /**
     * Truthiness of this expression against the environment. A bare reference
     * that lands on an array is truthy exactly when the array is non-empty —
     * the same rule the `default:` filter applies — so `[if @items]` and an
     * attached fallback always agree. Every other expression evaluates to a
     * scalar and follows the one falsy rule ({@see truthy}).
     */
    public function truthyIn(Environment $env): bool
    {
        if ($this->kind === self::REF) {
            $raw = $env->lookupRaw($this->data['segments']);
            if (is_array($raw)) {
                return $raw !== [];
            }
            return self::truthy($raw ?? '');
        }
        return self::truthy($this->evaluate($env));
    }

    /**
     * Falsy = the empty string, undefined (which resolves to ''), and numeric
     * zero (0, 0.0, 000, -0). Every other value is truthy — including a
     * non-numeric string like "0x".
     */
    public static function truthy(bool|string $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === '') {
            return false;
        }
        return !(self::isNumeric($value) && (float) $value === 0.0);
    }

    private function evalLogical(Environment $env): bool
    {
        $left = $this->data['left']->truthyIn($env);
        if ($this->data['op'] === 'and') {
            return $left && $this->data['right']->truthyIn($env);
        }
        return $left || $this->data['right']->truthyIn($env);
    }

    private function evalCompare(Environment $env): bool
    {
        $a = self::toStr($this->data['left']->evaluate($env));
        $b = self::toStr($this->data['right']->evaluate($env));
        $op = $this->data['op'];

        // Substring operators work on the text form, byte-wise and
        // case-sensitive (like string ==). PHP semantics: an empty needle
        // is always found.
        if ($op === 'contains' || $op === 'starts' || $op === 'ends') {
            return match ($op) {
                'contains' => str_contains($a, $b),
                'starts' => str_starts_with($a, $b),
                'ends' => str_ends_with($a, $b),
            };
        }

        // The two data types: a value is a Number when its text is numeric,
        // otherwise a String. Both operands numeric → numeric comparison (so
        // 5 == 5.0 and 007 == 7); otherwise lexicographic. This one rule
        // governs every operator, equality included.
        $cmp = (self::isNumeric($a) && self::isNumeric($b))
            ? ((float) $a <=> (float) $b)
            : strcmp($a, $b);

        return match ($op) {
            '==' => $cmp === 0,
            '!=' => $cmp !== 0,
            '<' => $cmp < 0,
            '>' => $cmp > 0,
            '<=' => $cmp <= 0,
            '>=' => $cmp >= 0,
            default => false,
        };
    }

    /**
     * Integer arithmetic. Both operands must be integers; a non-integer (or
     * division/modulo by zero) throws — the {@see Interpreter} catches it and
     * re-raises a {@see SyntaxError} with the `[= ...]` coordinates.
     */
    private function evalCount(Environment $env): string
    {
        $n = $env->sizeOf($this->data['segments']);
        if ($n === null) {
            $ref = implode('.', $this->data['segments']);
            throw new \RuntimeException("cannot count @$ref: not an array");
        }
        return (string) $n;
    }

    private function evalArith(Environment $env): string
    {
        $a = $this->intOf($this->data['left']->evaluate($env));
        $b = $this->intOf($this->data['right']->evaluate($env));

        return match ($this->data['op']) {
            '+' => (string) ($a + $b),
            '-' => (string) ($a - $b),
            '*' => (string) ($a * $b),
            '/' => $b === 0 ? throw new \RuntimeException('division by zero') : (string) intdiv($a, $b),
            '%' => $b === 0 ? throw new \RuntimeException('modulo by zero') : (string) ($a % $b),
            default => '',
        };
    }

    private function intOf(bool|string $value): int
    {
        $s = self::toStr($value);
        if (preg_match('/^[+-]?\d+$/', $s) !== 1) {
            throw new \RuntimeException("arithmetic requires integer operands, got '$s'");
        }
        return (int) $s;
    }

    /** A boolean textualizes as a Number: true → '1', false → '0'. */
    private static function toStr(bool|string $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : $value;
    }

    private static function isNumeric(string $value): bool
    {
        // An optional leading sign, either way: `+5` is the Number 5, exactly
        // as `-5` and `007` are Numbers (the text form is always preserved).
        return preg_match('/^[+-]?\d+(\.\d+)?$/', $value) === 1;
    }

    /**
     * True when a `[+-]?digits` text fits the platform integer range —
     * a PHP `(int)` cast silently saturates on overflow, so callers that
     * need a loud error verify with this first.
     */
    public static function intInRange(string $text): bool
    {
        $digits = ltrim(ltrim($text, '+-'), '0');
        if ($digits === '') {
            return true; // any spelling of zero
        }
        $canon = (str_starts_with($text, '-') ? '-' : '') . $digits;
        return (string) (int) $text === $canon;
    }
}
