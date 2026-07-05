<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Parses an expression out of the source. Used in two places:
 *   - `[if]` / `[elseif]` / `[for]` tags — closer `]`, arithmetic OFF;
 *   - an inline `@( ... )` block — closer `)`, arithmetic ON.
 *
 * References are always bare `@name` (the closed `@{...}` form is text-only).
 * A reference ends at the next boundary, so the parser consumes exactly the
 * expression tokens up to the closer and reports the byte offset immediately
 * after the last one; the {@see Lexer} resumes there (and expects the closer).
 *
 * Grammar (precedence low → high; the arithmetic levels are a no-op passthrough
 * when arithmetic is off, which keeps hyphenated barewords working in tags):
 *   or         := and ( 'or' and )*
 *   and        := not ( 'and' not )*
 *   not        := 'not' not | comparison
 *   comparison := additive ( cmpop additive )?
 *   cmpop      := '=='|'!='|'<'|'>'|'<='|'>=' | 'contains' | 'starts' 'with' | 'ends' 'with'
 *   additive   := multiplicative ( ('+'|'-') multiplicative )*
 *   multiplic. := unary ( ('*'|'/'|'%') unary )*
 *   unary      := ('-'|'+') unary | primary
 *   primary    := '(' or ')' | 'defined' ref | ref | literal
 *   ref        := '@' path
 *   literal    := '"' ... '"' | bareword          (bareword: no spaces)
 */
final class ExprParser
{
    private const KEYWORDS = ['and', 'or', 'not', 'defined', 'count'];

    /** @var array<int, array{type: string, value: mixed, start: int, end: int}> */
    private array $toks = [];
    private int $i = 0;

    private function __construct(
        private readonly string $source,
        private readonly int $line,
        private readonly int $lineStart,
        private readonly ?string $file,
        private readonly string $closer,
        private readonly bool $arithmetic,
    ) {
    }

    /**
     * Parse an expression. `$closer` is the character that ends it — `]` for an
     * `[if]`/`[for]` tag, `)` for an inline `@(...)`. `$arithmetic` enables the
     * `+ - * / %` operators (on inside `@(...)`, off in conditions so hyphenated
     * barewords keep working).
     *
     * @return array{0: Expr, 1: int} the expression and the byte offset where
     *                                the closer is expected
     */
    public static function parse(
        string $source,
        int $start,
        int $line,
        int $lineStart,
        ?string $file = null,
        string $closer = ']',
        bool $arithmetic = false,
    ): array {
        $p = new self($source, $line, $lineStart, $file, $closer, $arithmetic);
        $p->toks = $p->tokenize($start);

        if ($p->toks === []) {
            $p->fail($start, 'expected an expression');
        }

        $expr = $p->parseOr();
        if ($p->i < count($p->toks)) {
            $tok = $p->toks[$p->i];
            if ($tok['type'] === 'pipe') {
                $p->fail($tok['start'], 'filters are not supported in a tag condition (use @(...) or filter into a [set] first)');
            }
            $p->fail($tok['start'], "unexpected '{$p->tokenText($tok)}' in expression");
        }
        $end = $p->toks[$p->i - 1]['end']; // end of last consumed token
        return [$expr, $end];
    }

    /**
     * Like {@see parse}, but allows a trailing filter pipe chain
     * (`expr | filter: args, ... | filter`). Used by `@( ... )`.
     *
     * @return array{0: Expr, 1: int}
     */
    public static function parseWithFilters(
        string $source,
        int $start,
        int $line,
        int $lineStart,
        ?string $file,
        string $closer,
        bool $arithmetic,
    ): array {
        $p = new self($source, $line, $lineStart, $file, $closer, $arithmetic);
        $p->toks = $p->tokenize($start);

        if ($p->toks === []) {
            $p->fail($start, 'expected an expression');
        }

        $expr = $p->parseOr();
        $chain = $p->parseChain();
        if ($p->i < count($p->toks)) {
            $tok = $p->toks[$p->i];
            $p->fail($tok['start'], "unexpected '{$p->tokenText($tok)}' in expression");
        }
        if ($chain !== []) {
            $expr = Expr::filtered($expr, $chain);
        }
        $end = $p->toks[$p->i - 1]['end'];
        return [$expr, $end];
    }

    /**
     * Parse a bare filter chain starting at a `|` (used by `@{ path | ... }`,
     * where the head path is read by the {@see Lexer}).
     *
     * @return array{0: array<int, array{name: string, args: array<int, Expr>}>, 1: int}
     */
    public static function parseFilterChain(
        string $source,
        int $start,
        int $line,
        int $lineStart,
        ?string $file,
        string $closer,
    ): array {
        $p = new self($source, $line, $lineStart, $file, $closer, true);
        $p->toks = $p->tokenize($start);

        $chain = $p->parseChain();
        if ($chain === []) {
            $p->fail($start, "expected a '| filter' chain");
        }
        if ($p->i < count($p->toks)) {
            $tok = $p->toks[$p->i];
            $p->fail($tok['start'], "unexpected '{$p->tokenText($tok)}' in filter chain");
        }
        $end = $p->toks[$p->i - 1]['end'];
        return [$chain, $end];
    }

    /**
     * `('|' name (':' arg (',' arg)*)?)*` — each arg is a full expression.
     *
     * @return array<int, array{name: string, args: array<int, Expr>}>
     */
    private function parseChain(): array
    {
        $chain = [];
        while ($this->peekType() === 'pipe') {
            $this->i++;
            $tok = $this->toks[$this->i] ?? null;
            if ($tok === null || $tok['type'] !== 'lit'
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $tok['value']) !== 1) {
                $this->fail($this->lastEnd(), "expected a filter name after '|'");
            }
            $this->i++;

            $args = [];
            if ($this->peekType() === 'colon') {
                $this->i++;
                $args[] = $this->parseOr();
                while ($this->peekType() === 'comma') {
                    $this->i++;
                    $args[] = $this->parseOr();
                }
            }

            $chain[] = ['name' => $tok['value'], 'args' => $args];
        }
        return $chain;
    }

    // --- tokenizer -------------------------------------------------------

    /**
     * Tokenize the expression into candidate tokens. Stops at the closer — a
     * bare `]` (tag), or a `)` at paren-depth 0 (an `@(...)` block) — or EOF.
     *
     * @return array<int, array{type: string, value: mixed, start: int, end: int}>
     */
    private function tokenize(int $p): array
    {
        $len = strlen($this->source);
        $toks = [];
        $depth = 0;

        while ($p < $len) {
            $c = $this->source[$p];

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $p++;
                continue;
            }
            if ($c === ']' && $this->closer === ']') {
                break; // close of the tag
            }
            if ($c === ')' && $this->closer === ')' && $depth === 0) {
                break; // close of the @(...) block
            }
            if ($c === '}' && $this->closer === '}') {
                break; // close of the @{...} block (filter chain)
            }

            $start = $p;

            if ($c === '|') {
                $toks[] = ['type' => 'pipe', 'value' => '|', 'start' => $start, 'end' => ++$p];
                continue;
            }
            if ($c === ':') {
                $toks[] = ['type' => 'colon', 'value' => ':', 'start' => $start, 'end' => ++$p];
                continue;
            }
            if ($c === ',') {
                $toks[] = ['type' => 'comma', 'value' => ',', 'start' => $start, 'end' => ++$p];
                continue;
            }

            if ($c === '(') {
                $depth++;
                $toks[] = ['type' => 'lparen', 'value' => '(', 'start' => $start, 'end' => ++$p];
                continue;
            }
            if ($c === ')') {
                $depth--;
                $toks[] = ['type' => 'rparen', 'value' => ')', 'start' => $start, 'end' => ++$p];
                continue;
            }

            if ($this->arithmetic && str_contains('+-*/%', $c)) {
                $toks[] = ['type' => 'aop', 'value' => $c, 'start' => $start, 'end' => ++$p];
                continue;
            }

            if ($c === '@') {
                [$tok, $p] = $this->readRef($start);
                $toks[] = $tok;
                continue;
            }

            if ($c === '"') {
                [$tok, $p] = $this->readQuoted($start);
                $toks[] = $tok;
                continue;
            }

            if (str_contains('<>=!', $c)) {
                [$tok, $p] = $this->readOperator($start);
                $toks[] = $tok;
                continue;
            }

            // Bareword: literal or keyword.
            $word = '';
            while ($p < $len && !$this->isBoundary($this->source[$p])) {
                $word .= $this->source[$p];
                $p++;
            }
            $type = in_array($word, self::KEYWORDS, true) ? $word : 'lit';
            $toks[] = ['type' => $type, 'value' => $word, 'start' => $start, 'end' => $p];
        }

        return $toks;
    }

    /**
     * Read a bare `@path` reference (ends at the next boundary character).
     * Inside a tag the closed `@{path}` form is not used — the surrounding
     * operators / brackets already delimit the reference — so a `@{` here is a
     * mistake and gets a pointed error.
     *
     * @return array{0: array{type:string,value:mixed,start:int,end:int}, 1: int}
     */
    private function readRef(int $start): array
    {
        $len = strlen($this->source);
        $p = $start + 1; // past '@'

        if ($p < $len && $this->source[$p] === '{') {
            $this->fail($start, 'use a bare @name inside an expression (the @{...} form is for text only)');
        }

        $raw = '';
        while ($p < $len && !$this->isBoundary($this->source[$p])) {
            $raw .= $this->source[$p];
            $p++;
        }

        $segments = $this->pathSegments($raw, $start);
        return [['type' => 'ref', 'value' => $segments, 'start' => $start, 'end' => $p], $p];
    }

    /**
     * Split and validate a dot-path reference (no trailing-dot append here).
     *
     * @return array<int, string>
     */
    private function pathSegments(string $raw, int $start): array
    {
        return Environment::segmentsOf($raw)
            ?? $this->fail($start, "invalid reference: '@$raw'");
    }

    /**
     * Read a `"..."` string literal. The universal escape rule applies inside:
     * `\` before a non-alphanumeric character yields that character (`\"`,
     * `\\`, `\|`, …), cooked here since the literal is used directly; `\`
     * before a letter, digit or end-of-line stays literal (`"C:\Users"`).
     * May span newlines, like every other quoted value in the language.
     *
     * @return array{0: array{type:string,value:mixed,start:int,end:int}, 1: int}
     */
    private function readQuoted(int $start): array
    {
        $len = strlen($this->source);
        $p = $start + 1; // past opening quote
        $value = '';
        while ($p < $len) {
            $c = $this->source[$p];
            if ($c === '"') {
                $p++; // past closing quote
                return [['type' => 'lit', 'value' => $value, 'start' => $start, 'end' => $p], $p];
            }
            if ($c === '\\' && $p + 1 < $len && !ctype_alnum($this->source[$p + 1])
                && $this->source[$p + 1] !== "\n" && $this->source[$p + 1] !== "\r") {
                $value .= $this->source[$p + 1];
                $p += 2;
                continue;
            }
            $value .= $c;
            $p++;
        }
        $this->fail($start, 'unterminated "..." string in expression');
    }

    /** @return array{0: array{type:string,value:mixed,start:int,end:int}, 1: int} */
    private function readOperator(int $start): array
    {
        $two = substr($this->source, $start, 2);
        if (in_array($two, ['==', '!=', '<=', '>='], true)) {
            return [['type' => 'op', 'value' => $two, 'start' => $start, 'end' => $start + 2], $start + 2];
        }
        $one = $this->source[$start];
        if ($one === '<' || $one === '>') {
            return [['type' => 'op', 'value' => $one, 'start' => $start, 'end' => $start + 1], $start + 1];
        }
        $this->fail($start, "unexpected '$one' (use '==', '!=', '<', '>', '<=' or '>=')");
    }

    /** Characters that terminate a bareword (and a bare `@name` reference). */
    private function isBoundary(string $c): bool
    {
        return $c === ' ' || $c === "\t" || $c === "\r" || $c === "\n"
            || str_contains('(){}"<>=!]@|:,', $c)
            || ($this->arithmetic && str_contains('+-*/%', $c));
    }

    // --- recursive-descent parser ---------------------------------------

    private function parseOr(): Expr
    {
        $left = $this->parseAnd();
        while ($this->peekType() === 'or') {
            $this->i++;
            $left = Expr::logical('or', $left, $this->parseAnd());
        }
        return $left;
    }

    private function parseAnd(): Expr
    {
        $left = $this->parseNot();
        while ($this->peekType() === 'and') {
            $this->i++;
            $left = Expr::logical('and', $left, $this->parseNot());
        }
        return $left;
    }

    private function parseNot(): Expr
    {
        if ($this->peekType() === 'not') {
            $this->i++;
            return Expr::not($this->parseNot());
        }
        return $this->parseComparison();
    }

    private function parseComparison(): Expr
    {
        $left = $this->parseAdditive();
        if ($this->peekType() === 'op') {
            $op = $this->toks[$this->i]['value'];
            $this->i++;
            return Expr::compare($op, $left, $this->parseAdditive());
        }

        // Substring operators — context-sensitive words, recognized only in
        // operator position so `[if @x == contains]` keeps the bareword
        // literal. `starts` / `ends` require a following `with`.
        $word = $this->peekWordOp();
        if ($word !== null) {
            $this->i++;
            if ($word === 'starts' || $word === 'ends') {
                $with = $this->toks[$this->i] ?? null;
                if ($with === null || $with['type'] !== 'lit' || $with['value'] !== 'with') {
                    $this->fail($this->lastEnd(), "expected 'with' after '$word'");
                }
                $this->i++;
            }
            return Expr::compare($word, $left, $this->parseAdditive());
        }

        return $left;
    }

    /** `contains` / `starts` / `ends` when the next token is that bareword. */
    private function peekWordOp(): ?string
    {
        $tok = $this->toks[$this->i] ?? null;
        if ($tok !== null && $tok['type'] === 'lit'
            && in_array($tok['value'], ['contains', 'starts', 'ends'], true)) {
            return $tok['value'];
        }
        return null;
    }

    /** Additive level (`+ -`). A no-op passthrough when arithmetic is off. */
    private function parseAdditive(): Expr
    {
        if (!$this->arithmetic) {
            return $this->parsePrimary();
        }
        $left = $this->parseMultiplicative();
        while ($this->peekAop('+', '-')) {
            $op = $this->toks[$this->i]['value'];
            $this->i++;
            $left = Expr::arith($op, $left, $this->parseMultiplicative());
        }
        return $left;
    }

    /** Multiplicative level (`* / %`), binds tighter than `+ -`. */
    private function parseMultiplicative(): Expr
    {
        $left = $this->parseUnary();
        while ($this->peekAop('*', '/', '%')) {
            $op = $this->toks[$this->i]['value'];
            $this->i++;
            $left = Expr::arith($op, $left, $this->parseUnary());
        }
        return $left;
    }

    /** Unary minus (and a no-op unary plus), tighter than the binary operators. */
    private function parseUnary(): Expr
    {
        if ($this->peekAop('-')) {
            $this->i++;
            return Expr::neg($this->parseUnary());
        }
        if ($this->peekAop('+')) {
            $this->i++;
            return $this->parseUnary();
        }
        return $this->parsePrimary();
    }

    /** True if the next token is an arithmetic operator in $ops. */
    private function peekAop(string ...$ops): bool
    {
        return $this->peekType() === 'aop'
            && in_array($this->toks[$this->i]['value'], $ops, true);
    }

    private function parsePrimary(): Expr
    {
        $tok = $this->toks[$this->i] ?? null;
        if ($tok === null) {
            $this->fail($this->lastEnd(), 'unexpected end of expression');
        }

        switch ($tok['type']) {
            case 'lparen':
                $this->i++;
                $inner = $this->parseOr();
                if ($this->peekType() !== 'rparen') {
                    $this->fail($this->lastEnd(), "expected ')'");
                }
                $this->i++;
                return $inner;

            case 'defined':
            case 'count':
                // Context-sensitive, like the substring operators: an operator
                // only when followed by a @reference, otherwise an ordinary
                // bareword literal (so `[if @x == count]` compares the word).
                $ref = $this->toks[$this->i + 1] ?? null;
                if ($ref !== null && $ref['type'] === 'ref') {
                    $this->i += 2;
                    return $tok['type'] === 'defined'
                        ? Expr::defined($ref['value'])
                        : Expr::count($ref['value']);
                }
                $this->i++;
                return Expr::lit($tok['value']);

            case 'ref':
                $this->i++;
                return Expr::ref($tok['value']);

            case 'lit':
                $this->i++;
                return Expr::lit($tok['value']);

            default:
                $this->fail($tok['start'], "unexpected '{$this->tokenText($tok)}' in expression");
        }
    }

    /** Human form of a token for error messages (a ref's value is its segment array). */
    private function tokenText(array $tok): string
    {
        return $tok['type'] === 'ref'
            ? '@' . implode('.', $tok['value'])
            : (string) $tok['value'];
    }

    private function peekType(): ?string
    {
        return $this->toks[$this->i]['type'] ?? null;
    }

    private function lastEnd(): int
    {
        return $this->toks[$this->i - 1]['end'] ?? ($this->toks[0]['start'] ?? 0);
    }

    private function fail(int $offset, string $message): never
    {
        // The expression may span lines (headers wrap since newlines are
        // header whitespace), so count the newlines up to the error offset.
        // $lineStart may be negative for a re-lexed [set] value seated at a
        // template column; the virtual region before offset 0 is all on the
        // first line, so clamping the scan start keeps both cases exact.
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
