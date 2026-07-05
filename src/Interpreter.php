<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Walks the node tree left to right, mutating the {@see Environment} on `[set]`
 * and emitting text for literals and `[= ...]` tags. A single pass suffices
 * because a variable must be set before it is used (like a template): forward
 * references resolve to the strict/undefined policy.
 *
 * Only the branch of an `[if]` whose condition is truthy is executed, so
 * `[set]` side effects inside untaken branches never run. `[for]` iterates its
 * body, block-scoping the loop variables; `[break]` / `[continue]` unwind via
 * {@see BreakSignal} / {@see ContinueSignal}, caught by the nearest loop.
 */
final class Interpreter
{
    /**
     * Memoized parses of [set] values (source string → node list).
     *
     * @var array<string, array<int, Token|IfNode|ForNode>>
     */
    private array $valueCache = [];

    public function __construct(
        private readonly bool $strict = false,
        private readonly ?string $file = null,
        private readonly bool $trim = true,
    ) {
    }

    /**
     * @param array<int, Token|IfNode> $nodes
     */
    public function render(array $nodes, ?Environment $env = null): string
    {
        $env ??= new Environment();
        $out = '';
        $this->walk($nodes, $env, $out);
        return $out;
    }

    /**
     * Append the rendering of $nodes to $out.
     *
     * @param array<int, Token|IfNode> $nodes
     */
    private function walk(array $nodes, Environment $env, string &$out): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof IfNode) {
                $this->walkIf($node, $env, $out);
                continue;
            }
            if ($node instanceof ForNode) {
                $this->walkFor($node, $env, $out);
                continue;
            }

            switch ($node->type) {
                case Token::TEXT:
                    $out .= $node->value;
                    break;

                case Token::COMMENT:
                    break; // renders to nothing

                case Token::SET:
                    $this->applySet($node, $env);
                    break;

                case Token::UNSET:
                    foreach ($node->value['paths'] as $path) {
                        $env->remove($path);
                    }
                    break;

                case Token::EMIT:
                    $out .= $this->emit($node, $env);
                    break;

                case Token::BREAK:
                    throw new BreakSignal();

                case Token::CONTINUE:
                    throw new ContinueSignal();
            }
        }
    }

    /** Run the first branch whose condition is truthy, else the else body. */
    private function walkIf(IfNode $node, Environment $env, string &$out): void
    {
        foreach ($node->branches as $branch) {
            if ($this->truthyGuarded($branch['cond'], $env, $branch['line'], $branch['col'])) {
                $this->walk($branch['body'], $env, $out);
                return;
            }
        }
        if ($node->else !== null) {
            $this->walk($node->else, $env, $out);
        }
    }

    /**
     * Truthiness of a branch condition ({@see Expr::truthyIn} — array-aware
     * for bare references), converting a runtime error into a {@see SyntaxError}
     * at the branch's own tag coordinates.
     */
    private function truthyGuarded(Expr $expr, Environment $env, int $line, int $col): bool
    {
        try {
            return $expr->truthyIn($env);
        } catch (SyntaxError $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new SyntaxError($e->getMessage(), $line, $col, $this->file);
        }
    }

    /**
     * Evaluate an expression, converting a runtime error (e.g. `count` on a
     * scalar, or bad arithmetic) into a {@see SyntaxError} with coordinates.
     */
    private function evalGuarded(Expr $expr, Environment $env, int $line, int $col): bool|string
    {
        try {
            return $expr->evaluate($env);
        } catch (SyntaxError $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new SyntaxError($e->getMessage(), $line, $col, $this->file);
        }
    }

    private function walkFor(ForNode $node, Environment $env, string &$out): void
    {
        [$pairs, $count] = $this->resolveIterable($node, $env);

        // Block-scope the loop variables (and `loop`): remember prior bindings,
        // restore after. Nested loops each get their own `loop`.
        $keySnap = $node->keyVar !== null ? $env->snapshot($node->keyVar) : null;
        $valueSnap = $env->snapshot($node->valueVar);
        $loopSnap = $env->snapshot('loop');

        try {
            foreach ($pairs as $i => [$key, $value]) {
                if ($node->keyVar !== null) {
                    $env->bind($node->keyVar, (string) $key);
                }
                // A value may be a scalar or a sub-array (nested iteration):
                // bind it as-is so [= @v] renders empty but [= @v.child] resolves.
                $env->bind($node->valueVar, is_array($value) ? $value : (string) $value);

                // Per-iteration metadata, addressed as @loop.index etc.
                $env->bind('loop', [
                    'index' => (string) ($i + 1),
                    'index0' => (string) $i,
                    'first' => $i === 0 ? '1' : '0',
                    'last' => $i === $count - 1 ? '1' : '0',
                    'count' => (string) $count,
                ]);

                try {
                    $this->walk($node->body, $env, $out);
                } catch (ContinueSignal) {
                    continue;
                } catch (BreakSignal) {
                    break;
                }
            }
        } finally {
            if ($keySnap !== null) {
                $env->restore($node->keyVar, $keySnap);
            }
            $env->restore($node->valueVar, $valueSnap);
            $env->restore('loop', $loopSnap);
        }
    }

    /**
     * Resolve a loop's iterable into an ordered [key, value] sequence plus its
     * total length. An array is materialized (bounded by data that already
     * exists); a range streams lazily so a huge range costs time, not memory.
     *
     * @return array{0: iterable<int, array{0: int|string, 1: string|array<int|string, mixed>}>, 1: int}
     */
    private function resolveIterable(ForNode $node, Environment $env): array
    {
        $it = $node->iterable;
        if ($it['kind'] !== 'range') {
            $pairs = $this->arrayPairs($it, $node, $env);
            return [$pairs, count($pairs)];
        }

        $start = $this->bound($it['start'], $node, $env);
        $end = $this->bound($it['end'], $node, $env);
        $step = $it['step'] !== null ? $this->bound($it['step'], $node, $env) : 1;
        if ($step <= 0) {
            $this->fail($node, 'range step must be a positive integer');
        }

        // Length is arithmetic, never a materialized list. The span can
        // overflow the integer range (PHP_INT_MIN to PHP_INT_MAX); such a
        // loop is astronomically long, so saturating the count is academic.
        $span = $start <= $end ? $end - $start : $start - $end;
        $count = is_float($span) ? PHP_INT_MAX : intdiv($span, $step) + 1;
        return [$this->rangePairs($start, $end, $step), $count];
    }

    /**
     * @param array<string, mixed> $it
     * @return array<int, array{0: int|string, 1: string|array<int|string, mixed>}>
     */
    private function arrayPairs(array $it, ForNode $node, Environment $env): array
    {
        $raw = $env->lookupRaw($it['segments']);
        if ($raw === null) {
            return []; // undefined → zero iterations
        }
        if (!is_array($raw)) {
            $ref = implode('.', $it['segments']);
            $this->fail($node, "cannot iterate @{$ref}: not an array");
        }

        $pairs = [];
        foreach ($raw as $key => $value) {
            $pairs[] = [$key, $value];
        }
        return $pairs;
    }

    /**
     * Lazily yield a range's [index, n] pairs. `$n += $step` past PHP_INT_MAX
     * silently becomes a float that still compares <= (float) $end, so each
     * direction stops explicitly before the increment could overflow.
     *
     * @return \Generator<int, array{0: int, 1: int}>
     */
    private function rangePairs(int $start, int $end, int $step): \Generator
    {
        $idx = 0;
        if ($start <= $end) {
            for ($n = $start; $n <= $end; $n += $step) {
                yield [$idx++, $n];
                if ($n > PHP_INT_MAX - $step) {
                    return;
                }
            }
        } else {
            for ($n = $start; $n >= $end; $n -= $step) {
                yield [$idx++, $n];
                if ($n < PHP_INT_MIN + $step) {
                    return;
                }
            }
        }
    }

    /** @param array{type: string, value?: int, segments?: string[]} $bound */
    private function bound(array $bound, ForNode $node, Environment $env): int
    {
        if ($bound['type'] === 'int') {
            return $bound['value'];
        }
        $value = $env->lookup($bound['segments']);
        if ($value === null || preg_match('/^[+-]?\d+$/', $value) !== 1) {
            $ref = implode('.', $bound['segments']);
            $this->fail($node, "range bound @{$ref} must be an integer");
        }
        // (int) saturates silently on overflow — reject instead.
        if (!Expr::intInRange($value)) {
            $ref = implode('.', $bound['segments']);
            $this->fail($node, "range bound @{$ref} ('$value') is out of the integer range");
        }
        return (int) $value;
    }

    private function fail(ForNode $node, string $message): never
    {
        throw new SyntaxError($message, $node->line, $node->col, $this->file);
    }

    private function applySet(Token $token, Environment $env): void
    {
        ['segments' => $segments, 'append' => $append, 'expr' => $expr] = $token->value;

        // Values may themselves contain interpolations, resolved against the
        // environment as it stands at this point in the pass.
        $value = $this->renderValue($expr, $env, $token->value['vline'] ?? 1, $token->value['vcol'] ?? 1);

        try {
            $env->assign($segments, $append, $value);
        } catch (PathConflict $e) {
            throw new SyntaxError(
                $e->getMessage() . " (path '" . implode('.', $segments) . "')",
                $token->line,
                $token->col,
                $this->file,
            );
        }
    }

    /**
     * Render an `[= expr]` tag. A plain reference — filtered or not — keeps
     * direct-lookup semantics: the raw value feeds the filter chain (arrays
     * reach join/…), arrays without filters render empty, and strict mode
     * flags the undefined name. Any other expression evaluates and renders
     * its scalar result.
     */
    private function emit(Token $token, Environment $env): string
    {
        $expr = $token->value;
        if ($expr->kind === Expr::REF) {
            return $this->resolveRef($expr->data['segments'], [], $token, $env);
        }
        if ($expr->kind === Expr::FILTERED && $expr->data['inner']->kind === Expr::REF) {
            return $this->resolveRef($expr->data['inner']->data['segments'], $expr->data['chain'], $token, $env);
        }
        return Expr::render($this->evalGuarded($expr, $env, $token->line, $token->col));
    }

    /**
     * @param array<int, string> $segments
     * @param array<int, array{name: string, args: array<int, Expr>}> $filters
     */
    private function resolveRef(array $segments, array $filters, Token $token, Environment $env): string
    {
        $raw = $env->lookupRaw($segments);

        if ($filters === []) {
            if ($raw === null) {
                if ($this->strict) {
                    $ref = implode('.', $segments);
                    throw new SyntaxError("undefined reference @{$ref}", $token->line, $token->col, $this->file);
                }
                return '';
            }
            return is_string($raw) ? $raw : ''; // arrays are not renderable
        }

        // With filters, the raw value feeds the chain (arrays reach join/…).
        if ($raw === null) {
            if ($this->strict && !Filters::chainHasDefault($filters)) {
                $ref = implode('.', $segments);
                throw new SyntaxError("undefined reference @{$ref}", $token->line, $token->col, $this->file);
            }
            $raw = ''; // a `default` filter (or lax mode) handles it
        }

        try {
            $result = Filters::apply($raw, $filters, $env);
        } catch (SyntaxError $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new SyntaxError($e->getMessage(), $token->line, $token->col, $this->file);
        }

        if (is_array($result)) {
            throw new SyntaxError('filter result is an array (add a join)', $token->line, $token->col, $this->file);
        }
        return $result;
    }

    /**
     * Render a [set] value exactly like body text — into the variable instead
     * of the output. Block values are mini-templates: `[if]`, `[for]` and
     * nested self-closing `[set]`s execute; `[= ...]`/escapes resolve.
     * Parsed values are memoized per source string so a `[set]` inside a loop
     * does not re-parse on every iteration; the cache is bounded by the number
     * of distinct set-values in the template.
     */
    private function renderValue(string $expr, Environment $env, int $line, int $col): string
    {
        // Fast path: every construct starts with '\' or '[' — text
        // containing neither renders as itself.
        if (strcspn($expr, '\\[') === strlen($expr)) {
            return $expr;
        }
        // Site-keyed so identical values at different positions each carry
        // their own exact coordinates; a loop re-rendering the same [set]
        // still hits the cache.
        $key = $line . ':' . $col . '|' . $expr;
        $nodes = $this->valueCache[$key] ??= $this->parseValue($expr, $line, $col);
        return $this->render($nodes, $env);
    }

    /** @return array<int, Token|IfNode|ForNode> */
    private function parseValue(string $expr, int $line, int $col): array
    {
        $tokens = Lexer::tokenize($expr, $this->file, $line, $col);
        if ($this->trim) {
            $tokens = Trimmer::trim($tokens);
        }
        return Parser::parse($tokens, $this->file);
    }
}
