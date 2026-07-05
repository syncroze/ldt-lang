<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Parses the header of a `[for]` loop — the `vars in <iterable>` part — out of
 * the source, starting just after the `[for` keyword. The {@see Lexer} then
 * expects the tag-closing `]`.
 *
 * Grammar:
 *   header   := name (',' name)? 'in' iterable
 *   iterable := ref                                     (array)
 *             | bound 'to' bound ( 'by' bound )?        (range, inclusive)
 *   bound    := '-'? digit+  |  ref
 *   ref      := '@' path
 *
 * The `to` keyword disambiguates the two forms after the first bound is read,
 * so a range's start may itself be a `@ref` (e.g. `@lo to @hi by @step`).
 */
final class ForHeader
{
    private int $pos;
    private readonly int $len;

    private function __construct(
        private readonly string $source,
        private readonly int $line,
        private readonly int $lineStart,
        private readonly ?string $file,
        int $start,
    ) {
        $this->pos = $start;
        $this->len = strlen($source);
    }

    /**
     * @return array{0: array{keyVar: ?string, valueVar: string, iterable: array<string, mixed>}, 1: int}
     */
    public static function parse(string $source, int $start, int $line, int $lineStart, ?string $file = null): array
    {
        return (new self($source, $line, $lineStart, $file, $start))->run();
    }

    /** @return array{0: array{keyVar: ?string, valueVar: string, iterable: array<string, mixed>}, 1: int} */
    private function run(): array
    {
        $this->skipWs();
        $firstStart = $this->pos;
        $first = $this->readIdent();
        $this->skipWs();

        $keyVar = null;
        $valueVar = $first;
        $valueStart = $firstStart;
        if ($this->peek() === ',') {
            $this->pos++;
            $this->skipWs();
            $keyVar = $first;
            $valueStart = $this->pos;
            $valueVar = $this->readIdent();
            $this->skipWs();
        }

        if ($keyVar !== null && $keyVar === $valueVar) {
            $this->fail($this->pos, 'loop key and value variables must differ');
        }
        // `loop` is bound to the per-iteration metadata after the loop
        // variables, so a loop variable named `loop` would be unreachable —
        // reject it loudly (a user variable named loop is merely shadowed).
        if ($keyVar === 'loop') {
            $this->fail($firstStart, "'loop' is reserved for loop metadata inside [for]");
        }
        if ($valueVar === 'loop') {
            $this->fail($valueStart, "'loop' is reserved for loop metadata inside [for]");
        }

        $this->expectIn();
        $this->skipWs();
        $iterable = $this->readIterable();

        $spec = ['keyVar' => $keyVar, 'valueVar' => $valueVar, 'iterable' => $iterable];
        return [$spec, $this->pos];
    }

    /**
     * Read the iterable: a `@ref` to an array, or a `bound to bound (by bound)`
     * range. Both may start with a `@ref`, so we read the first bound and then
     * look for the `to` keyword — if it is there, it is a range (whose start may
     * be a ref); otherwise the first token must be a lone array reference.
     *
     * @return array<string, mixed>
     */
    private function readIterable(): array
    {
        $first = $this->readBound();
        $this->skipWs();

        if ($this->matchesKeyword('to')) {
            $this->pos += 2;
            $end = $this->readBound();

            $step = null;
            $this->skipWs();
            if ($this->matchesKeyword('by')) {
                $this->pos += 2;
                $step = $this->readBound();
            }

            return ['kind' => 'range', 'start' => $first, 'end' => $end, 'step' => $step];
        }

        if ($first['type'] === 'ref') {
            return ['kind' => 'array', 'segments' => $first['segments']];
        }
        $this->fail($this->pos, "expected 'to' in range (or a @reference to iterate)");
    }

    /** @return array{type: string, value?: int, segments?: string[]} */
    private function readBound(): array
    {
        $this->skipWs();
        if ($this->peek() === '@') {
            return ['type' => 'ref', 'segments' => $this->readRef()];
        }

        $start = $this->pos;
        if ($this->peek() === '-' || $this->peek() === '+') {
            $this->pos++;
        }
        $n = strspn($this->source, '0123456789', $this->pos);
        $digits = substr($this->source, $this->pos, $n);
        $this->pos += $n;
        if ($digits === '') {
            $this->fail($start, 'expected an integer or @reference in range');
        }
        $text = substr($this->source, $start, $this->pos - $start);
        // A PHP (int) cast saturates silently past PHP_INT_MAX — reject the
        // literal instead of quietly looping to a different bound.
        if (!Expr::intInRange($text)) {
            $this->fail($start, "range bound '$text' is out of the integer range");
        }
        return ['type' => 'int', 'value' => (int) $text];
    }

    /**
     * Read a bare `@path` reference and return its validated dot-path segments.
     *
     * @return array<int, string>
     */
    private function readRef(): array
    {
        $start = $this->pos;
        $this->pos++; // past '@'

        $n = strspn($this->source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-', $this->pos);
        $raw = substr($this->source, $this->pos, $n);
        $this->pos += $n;

        return Environment::segmentsOf($raw)
            ?? $this->fail($start, "invalid reference: '@$raw'");
    }

    private function matchesKeyword(string $kw): bool
    {
        $n = strlen($kw);
        return substr($this->source, $this->pos, $n) === $kw && $this->boundaryAt($this->pos + $n);
    }

    private function readIdent(): string
    {
        $c = $this->pos < $this->len ? $this->source[$this->pos] : '';
        if ($c === '' || (!ctype_alpha($c) && $c !== '_')) {
            $this->fail($this->pos, 'expected a loop variable name');
        }
        $n = strspn($this->source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_', $this->pos);
        $id = substr($this->source, $this->pos, $n);
        $this->pos += $n;
        return $id;
    }

    private function expectIn(): void
    {
        if (substr($this->source, $this->pos, 2) === 'in' && $this->boundaryAt($this->pos + 2)) {
            $this->pos += 2;
            return;
        }
        $this->fail($this->pos, "expected 'in' in [for header");
    }

    private function skipWs(): void
    {
        $this->pos += strspn($this->source, " \t\r\n", $this->pos);
    }

    private function boundaryAt(int $offset): bool
    {
        if ($offset >= $this->len) {
            return true;
        }
        $c = $this->source[$offset];
        return !ctype_alnum($c) && $c !== '_';
    }

    private function peek(): ?string
    {
        return $this->pos < $this->len ? $this->source[$this->pos] : null;
    }

    private function peekAt(int $ahead): ?string
    {
        return ($this->pos + $ahead) < $this->len ? $this->source[$this->pos + $ahead] : null;
    }

    private function fail(int $offset, string $message): never
    {
        // Headers may wrap across lines; count the newlines up to the error
        // offset so continuation-line errors report their real position.
        // $lineStart may be negative for a re-lexed [set] value seated at a
        // template column (see {@see ExprParser::fail}).
        $line = $this->line;
        $lineStart = $this->lineStart;
        $from = max(0, $lineStart);
        if ($offset > $from) {
            $chunk = substr($this->source, $from, $offset - $from);
            $newlines = substr_count($chunk, "\n");
            if ($newlines > 0) {
                $line += $newlines;
                $lineStart = $from + (int) strrpos($chunk, "\n") + 1;
            }
        }
        throw new SyntaxError($message, $line, $offset - $lineStart + 1, $this->file);
    }
}
