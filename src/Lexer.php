<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Turns .ldt source text into a flat stream of {@see Token}s.
 *
 * The language is embedded in arbitrary text (like PHP), so the scanner copies
 * bytes to TEXT tokens until it meets a bracket construct:
 *
 *   - a comment: `[# ... #]` (renders to nothing, may span lines);
 *   - a set directive, in either form:
 *       block:         `[set path]value[/set]`
 *       self-closing:  `[set path = value]`
 *   - a conditional: `[if <cond>]` / `[elseif <cond>]` / `[else]` / `[/if]`
 *     (the condition is parsed by {@see ExprParser}); the flat IF/ELSEIF/
 *     ELSE/ENDIF tokens are later assembled into a tree by {@see Parser};
 *   - a loop: `[for <header>]` / `[/for]` with `[break]` / `[continue]`
 *     (the header is parsed by {@see ForHeader});
 *   - a removal: `[unset a, b.c]` (each path becomes undefined);
 *   - an interpolation: `@{ path }`;
 *   - an inline expression: `@( expr )` (parsed by {@see ExprParser}).
 *
 * A `path` is a dot-path (`user.first`, `fruit.0`); a trailing dot on a set
 * target (`fruit.`) means "append at the next index". A `\` escapes the next
 * non-alphanumeric character (so `\@{`, `\[set`, `\#]` are literal); everything
 * else — including a lone `[` or a lone `@` — is literal text.
 */
final class Lexer
{
    private int $pos = 0;
    private int $line = 1;
    private int $col = 1;
    private int $len;

    /** @var Token[] */
    private array $tokens = [];

    /** Accumulates literal text between constructs. */
    private string $buffer = '';
    private int $bufLine = 1;
    private int $bufCol = 1;

    /** Characters that may appear in a dot-path token (for strspn). */
    private const PATH_CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

    public function __construct(
        private readonly string $source,
        private readonly ?string $file = null,
        int $baseLine = 1,
        int $baseCol = 1,
    ) {
        $this->len = strlen($source);
        $this->line = $baseLine;
        $this->col = $baseCol;
        $this->bufLine = $baseLine;
        $this->bufCol = $baseCol;
    }

    /**
     * Tokenize source. $baseLine/$baseCol seat the coordinate counters so a
     * re-lexed [set] value reports exact template positions, not value-relative
     * ones.
     *
     * @return Token[]
     */
    public static function tokenize(string $source, ?string $file = null, int $baseLine = 1, int $baseCol = 1): array
    {
        return (new self($source, $file, $baseLine, $baseCol))->run();
    }

    /** @return Token[] */
    public function run(): array
    {
        while ($this->pos < $this->len) {
            // Bulk-copy the literal run up to the next character that can
            // start a construct ('\', '@' or '['). Plain text is by far the
            // common case, so it must not pay per-byte construct checks.
            $run = strcspn($this->source, '\\@[', $this->pos);
            if ($run > 0) {
                $this->pushText(substr($this->source, $this->pos, $run));
                $this->advanceBy($run);
                if ($this->pos >= $this->len) {
                    break;
                }
            }

            $ch = $this->source[$this->pos];

            if ($ch === '\\') {
                $this->readEscape();
                continue;
            }

            if ($ch === '@') {
                if ($this->matches('@{')) {
                    $this->readInterp();
                    continue;
                }
                if ($this->matches('@(')) {
                    $this->readExpr();
                    continue;
                }
            } elseif ($ch === '[') {
                if ($this->matches('[#')) {
                    $this->readComment();
                    continue;
                }
                if ($this->isSetAt($this->pos)) {
                    $this->readSet();
                    continue;
                }
                if ($this->matches('[unset') && $this->boundaryAt($this->pos + 6)) {
                    $this->readUnset();
                    continue;
                }

                switch ($this->keywordAt($this->pos)) {
                    case 'if':
                        $this->readCondition(Token::IF, '[if');
                        continue 2;
                    case 'elseif':
                        $this->readCondition(Token::ELSEIF, '[elseif');
                        continue 2;
                    case 'else':
                        $this->readMarker(Token::ELSE, '[else');
                        continue 2;
                    case 'endif':
                        $this->readMarker(Token::ENDIF, '[/if');
                        continue 2;
                    case 'for':
                        $this->readFor();
                        continue 2;
                    case 'endfor':
                        $this->readMarker(Token::ENDFOR, '[/for');
                        continue 2;
                    case 'break':
                        $this->readMarker(Token::BREAK, '[break');
                        continue 2;
                    case 'continue':
                        $this->readMarker(Token::CONTINUE, '[continue');
                        continue 2;
                }
            }

            // A lone '\', '@' or '[' that starts no construct is literal.
            $this->pushChar($ch);
            $this->advance();
        }

        $this->flush();
        return $this->tokens;
    }

    /** `[set` followed by a word boundary (so `[setup]` stays literal). */
    private function isSetAt(int $p): bool
    {
        return $this->matchesAt('[set', $p) && $this->boundaryAt($p + 4);
    }

    private function readComment(): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume('[#');
        // Body is discarded, but `\#]` keeps a literal closer from ending it.
        $body = $this->readEscapedUntil('#]', $startLine, $startCol, 'unterminated [# ... #] comment');
        $this->consume('#]');

        $this->tokens[] = new Token(Token::COMMENT, $body, $startLine, $startCol);
    }

    private function readSet(): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume('[set');
        $this->skipAllWs();

        $rawPath = $this->readPathText();
        if ($rawPath === '') {
            $this->failAt($startLine, $startCol, 'expected a variable path after [set');
        }
        [$segments, $append] = $this->parsePath($rawPath, allowAppend: true, line: $startLine, col: $startCol);

        $this->skipAllWs();
        $ch = $this->peek();

        if ($ch === '=') {
            // Self-closing: the value runs to the tag-closing ']' — the same
            // closer every other tag uses. A value starting with '"' is a
            // quoted whole value: quotes protect everything inside (including
            // ']' and edge spaces). Unquoted values write a literal ']' as \].
            $this->advance();
            $this->skipAllWs();

            if ($this->peek() === '"') {
                $vLine = $this->line;
                $vCol = $this->col + 1; // content begins after the opening quote
                $value = $this->readQuotedValue($startLine, $startCol, $rawPath);
                $this->skipAllWs();
                if ($this->peek() !== ']') {
                    $this->failAt($startLine, $startCol, "[set] value for '$rawPath' has content after the closing quote (expected ']')");
                }
                $this->advance(); // ]
            } else {
                $vLine = $this->line;
                $vCol = $this->col;
                $expr = $this->readEscapedUntil(']', $startLine, $startCol, "unterminated [set ...] value for '$rawPath'");
                $this->advance(); // ]
                $value = trim($expr);
            }
        } elseif ($ch === ']') {
            // Block: value runs from ']' to the closing '[/set]'. Same mode
            // split as the '=' form: a body whose first non-whitespace char is
            // '"' is a quoted whole value — quotes protect everything inside,
            // including a literal '[/set]'.
            $this->advance();
            $this->skipAllWs();

            if ($this->peek() === '"') {
                $vLine = $this->line;
                $vCol = $this->col + 1; // content begins after the opening quote
                $value = $this->readQuotedValue($startLine, $startCol, $rawPath);
                $this->skipAllWs();
                if (!$this->matches('[/set]')) {
                    $this->failAt($startLine, $startCol, "[set] value for '$rawPath' has content after the closing quote (expected [/set])");
                }
                $this->consume('[/set]');
            } else {
                $vLine = $this->line;
                $vCol = $this->col;
                $expr = $this->readEscapedUntil('[/set]', $startLine, $startCol, "unterminated [set] (missing [/set]) for '$rawPath'");
                $this->consume('[/set]');
                $value = trim($expr);
            }
        } else {
            $this->failAt($startLine, $startCol, "expected '=' or ']' in [set for '$rawPath'");
        }

        $this->tokens[] = new Token(Token::SET, [
            'segments' => $segments,
            'append' => $append,
            'expr' => $value,
            'vline' => $vLine, // where the value text begins in the template,
            'vcol' => $vCol,   // so its re-lex reports exact coordinates
        ], $startLine, $startCol);
    }

    /**
     * Read a quoted `[set … = "…"]` value: called at the opening quote,
     * consumes through the closing quote. Everything inside is protected —
     * `]`, newlines, edge spaces; interior escapes (`\"`, `\\`, …) are kept
     * verbatim and cook at render time.
     */
    private function readQuotedValue(int $line, int $col, string $rawPath): string
    {
        $this->advance(); // opening "
        $out = '';
        while ($this->pos < $this->len) {
            $run = strcspn($this->source, '\\"', $this->pos);
            if ($run > 0) {
                $out .= substr($this->source, $this->pos, $run);
                $this->advanceBy($run);
                if ($this->pos >= $this->len) {
                    break;
                }
            }
            if ($this->source[$this->pos] === '\\') {
                $out .= '\\';
                $this->advance();
                if ($this->pos < $this->len) {
                    $out .= $this->source[$this->pos];
                    $this->advance();
                }
                continue;
            }
            $this->advance(); // closing "
            return $out;
        }
        $this->failAt($line, $col, "unterminated quoted [set] value for '$rawPath' (escape a literal leading quote as \\\")");
    }

    /**
     * Read `[unset a, b.c]` — one or more comma-separated dot-paths, each to
     * be removed entirely. No trailing-dot (append has no meaning here).
     */
    private function readUnset(): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume('[unset');
        $this->skipAllWs();

        $paths = [];
        while (true) {
            $raw = $this->readPathText();
            if ($raw === '') {
                $this->failAt($startLine, $startCol, 'expected a variable path in [unset');
            }
            [$segments] = $this->parsePath($raw, allowAppend: false, line: $startLine, col: $startCol);
            $paths[] = $segments;

            $this->skipAllWs();
            if ($this->peek() === ',') {
                $this->advance();
                $this->skipAllWs();
                continue;
            }
            break;
        }

        if ($this->peek() !== ']') {
            $this->failAt($startLine, $startCol, "expected ']' to close [unset");
        }
        $this->advance(); // ]

        $this->tokens[] = new Token(Token::UNSET, ['paths' => $paths], $startLine, $startCol);
    }

    private function readInterp(): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume('@{');

        // Head: the dot-path, up to the first `|` (a filter chain) or the
        // closing `}`. Fallbacks are a filter (`| default: …`), so the head
        // is nothing but a path.
        $head = '';
        $sawPipe = false;
        while (true) {
            $c = $this->peek();
            if ($c === null) {
                $this->failAt($startLine, $startCol, 'unterminated @{ ... } interpolation');
            }
            if ($c === '}') {
                break;
            }
            if ($c === '|') {
                $sawPipe = true;
                break;
            }
            $head .= $c;
            $this->advance();
        }

        // Optional trailing filter chain, parsed by ExprParser (args are full
        // expressions). The chain ends at the closing `}`.
        $filters = [];
        if ($sawPipe) {
            $lineStart = $this->pos - ($this->col - 1);
            [$filters, $end] = ExprParser::parseFilterChain($this->source, $this->pos, $this->line, $lineStart, $this->file, '}');
            $this->advanceBy($end - $this->pos);
            $this->skipAllWs();
        }
        if ($this->peek() !== '}') {
            $this->failAt($startLine, $startCol, 'unterminated @{ ... } interpolation');
        }
        $this->advance(); // }

        [$segments] = $this->parsePath(trim($head), allowAppend: false, line: $startLine, col: $startCol);
        $this->tokens[] = new Token(Token::INTERP, [
            'segments' => $segments,
            'filters' => $filters,
        ], $startLine, $startCol);
    }

    /**
     * Read an inline `@( expr )` expression. The expression self-delimits at the
     * `)` that balances the opening `@(`; {@see ExprParser} parses it with
     * arithmetic enabled and reports where that `)` is expected.
     */
    private function readExpr(): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume('@(');
        $lineStart = $this->pos - ($this->col - 1);
        [$expr, $end] = ExprParser::parseWithFilters($this->source, $this->pos, $this->line, $lineStart, $this->file, ')', true);
        $this->advanceBy($end - $this->pos);
        $this->skipAllWs();

        if ($this->peek() !== ')') {
            $this->failAt($startLine, $startCol, "expected ')' to close @(");
        }
        $this->advance(); // )

        $this->tokens[] = new Token(Token::EXPR, $expr, $startLine, $startCol);
    }

    /**
     * Identify a block-keyword tag beginning at byte offset $p. `[if`/`[elseif`/
     * `[for` require a trailing word boundary so `[iffy]`/`[format]` stay
     * literal; the marker tags are matched whole.
     */
    private function keywordAt(int $p): ?string
    {
        if ($this->matchesAt('[elseif', $p) && $this->boundaryAt($p + 7)) {
            return 'elseif';
        }
        if ($this->matchesAt('[if', $p) && $this->boundaryAt($p + 3)) {
            return 'if';
        }
        if ($this->matchesAt('[else', $p) && $this->markerCloses($p + 5)) {
            return 'else';
        }
        if ($this->matchesAt('[/if', $p) && $this->markerCloses($p + 4)) {
            return 'endif';
        }
        if ($this->matchesAt('[for', $p) && $this->boundaryAt($p + 4)) {
            return 'for';
        }
        if ($this->matchesAt('[/for', $p) && $this->markerCloses($p + 5)) {
            return 'endfor';
        }
        if ($this->matchesAt('[break', $p) && $this->markerCloses($p + 6)) {
            return 'break';
        }
        if ($this->matchesAt('[continue', $p) && $this->markerCloses($p + 9)) {
            return 'continue';
        }
        return null;
    }

    /**
     * An argument-less marker tag closes with `]`, optionally after whitespace
     * (newlines included — the same rule as every other tag header), so
     * `[else ]` and `[break ]` are tags while `[else x]` stays literal.
     */
    private function markerCloses(int $p): bool
    {
        $p += strspn($this->source, " \t\r\n", $p);
        return $p < $this->len && $this->source[$p] === ']';
    }

    private function boundaryAt(int $offset): bool
    {
        if ($offset >= $this->len) {
            return true;
        }
        $c = $this->source[$offset];
        return !ctype_alnum($c) && $c !== '_';
    }

    /**
     * Read `[if` / `[elseif` plus its condition. The condition self-delimits
     * (a `@{...}` ref closes at its own `}` and a bare `@name` ends at the next
     * boundary), so {@see ExprParser} consumes exactly the boolean expression
     * and the first bare `]` closes the tag. Body text begins after that `]`.
     */
    private function readCondition(string $tokenType, string $keyword): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume($keyword);
        $lineStart = $this->pos - ($this->col - 1);
        [$expr, $end] = ExprParser::parse($this->source, $this->pos, $this->line, $lineStart, $this->file);
        $this->advanceBy($end - $this->pos);
        $this->skipAllWs(); // whitespace between the condition and its ']'

        if ($this->peek() !== ']') {
            $this->failAt($startLine, $startCol, "expected ']' to close $keyword");
        }
        $this->advance(); // ]

        $this->tokens[] = new Token($tokenType, $expr, $startLine, $startCol);
    }

    /**
     * Read `[for <header>]`. Like a condition, the header self-delimits and
     * {@see ForHeader} reports where the closing `]` is expected. Body text
     * begins immediately after that `]`.
     */
    private function readFor(): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume('[for');
        $lineStart = $this->pos - ($this->col - 1);
        [$spec, $end] = ForHeader::parse($this->source, $this->pos, $this->line, $lineStart, $this->file);
        $this->advanceBy($end - $this->pos);
        $this->skipAllWs(); // whitespace between the header and its ']'

        if ($this->peek() !== ']') {
            $this->failAt($startLine, $startCol, "expected ']' to close [for");
        }
        $this->advance(); // ]

        $this->tokens[] = new Token(Token::FOR, $spec, $startLine, $startCol);
    }

    /**
     * Read an argument-less marker tag (`[else]` / `[/if]` / `[/for]` /
     * `[break]` / `[continue]`), whitespace before the `]` allowed —
     * {@see keywordAt} already verified the closer via {@see markerCloses}.
     */
    private function readMarker(string $tokenType, string $keyword): void
    {
        $this->flush();
        $startLine = $this->line;
        $startCol = $this->col;

        $this->consume($keyword);
        $this->skipAllWs();
        $this->advance(); // the verified ']'
        $this->tokens[] = new Token($tokenType, null, $startLine, $startCol);
    }

    /**
     * Split a raw dot-path into segments and detect a trailing-dot append.
     * Validation is shared with the other parsers ({@see Environment::segmentsOf}).
     *
     * @return array{0: array<int, string>, 1: bool}
     */
    private function parsePath(string $raw, bool $allowAppend, int $line, int $col): array
    {
        $append = false;
        if (str_ends_with($raw, '.')) {
            if (!$allowAppend) {
                $this->failAt($line, $col, "a trailing '.' (append) is not allowed in a reference: '$raw'");
            }
            $append = true;
            $raw = substr($raw, 0, -1);
        }

        $segments = Environment::segmentsOf($raw);
        if ($segments === null) {
            $this->failAt($line, $col, "invalid path '$raw'");
        }

        return [$segments, $append];
    }

    // --- low-level scanning helpers -------------------------------------

    /** Read the run of path characters at the cursor (no consumption beyond). */
    private function readPathText(): string
    {
        $n = strspn($this->source, self::PATH_CHARSET, $this->pos);
        if ($n === 0) {
            return '';
        }
        $out = substr($this->source, $this->pos, $n);
        $this->advanceBy($n);
        return $out;
    }

    /**
     * Like {@see readUntil}, but a `\` keeps the following character raw so an
     * escaped closer (e.g. `\[/set]`, `\#]`) does not terminate the region. The
     * backslash is preserved verbatim; escapes are resolved later — for `[set]`
     * values by re-lexing (see {@see Interpreter::renderValue}), for
     * comments not at all (the body is discarded).
     */
    private function readEscapedUntil(string $needle, int $line, int $col, string $error): string
    {
        $out = '';
        $stops = '\\' . $needle[0]; // only these two chars need a closer look
        while ($this->pos < $this->len) {
            $run = strcspn($this->source, $stops, $this->pos);
            if ($run > 0) {
                $out .= substr($this->source, $this->pos, $run);
                $this->advanceBy($run);
                if ($this->pos >= $this->len) {
                    break;
                }
            }
            if ($this->source[$this->pos] === '\\') {
                $out .= '\\';
                $this->advance();
                if ($this->pos < $this->len) {
                    $out .= $this->source[$this->pos];
                    $this->advance();
                }
                continue;
            }
            if ($this->matches($needle)) {
                return $out;
            }
            $out .= $this->source[$this->pos];
            $this->advance();
        }
        $this->failAt($line, $col, $error);
    }

    /**
     * Handle a `\` escape in body text. A backslash before any non-alphanumeric
     * character emits that character literally (so `\@{`, `\[set`, `\}` and `\\`
     * are written as-is); before a letter, digit, end-of-line or end-of-input
     * the backslash itself is literal (so Windows paths like `C:\Users` and a
     * trailing prose backslash survive untouched).
     */
    private function readEscape(): void
    {
        $next = $this->pos + 1 < $this->len ? $this->source[$this->pos + 1] : null;
        if ($next !== null && !ctype_alnum($next) && $next !== "\n" && $next !== "\r") {
            $this->pushChar($next);
            $this->advanceBy(2);
        } else {
            $this->pushChar('\\');
            $this->advance();
        }
    }

    private function skipInlineWs(): void
    {
        while ($this->pos < $this->len && ($this->peek() === ' ' || $this->peek() === "\t")) {
            $this->advance();
        }
    }

    /** Skip all whitespace, newlines included (block-set bodies span lines). */
    private function skipAllWs(): void
    {
        $n = strspn($this->source, " \t\r\n", $this->pos);
        if ($n > 0) {
            $this->advanceBy($n);
        }
    }

    private function matches(string $needle): bool
    {
        return $this->matchesAt($needle, $this->pos);
    }

    private function matchesAt(string $needle, int $p): bool
    {
        return substr_compare($this->source, $needle, $p, strlen($needle)) === 0;
    }

    private function consume(string $needle): void
    {
        if (!$this->matches($needle)) {
            $this->fail("expected '$needle'");
        }
        $this->advanceBy(strlen($needle));
    }

    private function peek(): ?string
    {
        return $this->pos < $this->len ? $this->source[$this->pos] : null;
    }

    private function pushChar(string $ch): void
    {
        if ($this->buffer === '') {
            $this->bufLine = $this->line;
            $this->bufCol = $this->col;
        }
        $this->buffer .= $ch;
    }

    /** Append a literal run to the text buffer (bulk {@see pushChar}). */
    private function pushText(string $text): void
    {
        if ($this->buffer === '') {
            $this->bufLine = $this->line;
            $this->bufCol = $this->col;
        }
        $this->buffer .= $text;
    }

    private function flush(): void
    {
        if ($this->buffer !== '') {
            $this->tokens[] = new Token(Token::TEXT, $this->buffer, $this->bufLine, $this->bufCol);
            $this->buffer = '';
        }
    }

    private function advance(): void
    {
        if ($this->source[$this->pos] === "\n") {
            $this->line++;
            $this->col = 1;
        } else {
            $this->col++;
        }
        $this->pos++;
    }

    /** Advance $n bytes, updating line/col in bulk (not per character). */
    private function advanceBy(int $n): void
    {
        $n = min($n, $this->len - $this->pos);
        if ($n <= 0) {
            return;
        }
        $chunk = substr($this->source, $this->pos, $n);
        $newlines = substr_count($chunk, "\n");
        if ($newlines > 0) {
            $this->line += $newlines;
            $this->col = $n - ((int) strrpos($chunk, "\n") + 1) + 1;
        } else {
            $this->col += $n;
        }
        $this->pos += $n;
    }

    private function fail(string $message): never
    {
        throw new SyntaxError($message, $this->line, $this->col, $this->file);
    }

    private function failAt(int $line, int $col, string $message): never
    {
        throw new SyntaxError($message, $line, $col, $this->file);
    }
}
