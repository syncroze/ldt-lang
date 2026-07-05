<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * A parsed `[if] / [elseif]* / [else]? / [/if]` block.
 *
 * Each branch pairs a condition {@see Expr} with a body — a list of child
 * nodes (leaf {@see Token}s and nested IfNodes). The optional else body runs
 * when no branch condition is truthy.
 */
final class IfNode
{
    /**
     * @param array<int, array{cond: Expr, body: array<int, Token|IfNode>, line: int, col: int}> $branches
     *        (line/col are the branch's own tag — [if] or [elseif] — so
     *        condition errors report the right position)
     * @param array<int, Token|IfNode>|null $else
     */
    public function __construct(
        public readonly array $branches,
        public readonly ?array $else,
        public readonly int $line,
        public readonly int $col,
    ) {
    }
}
