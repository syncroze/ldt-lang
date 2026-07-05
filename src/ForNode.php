<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * A parsed `[for … in …] … [/for]` loop.
 *
 * `keyVar` is null for the one-variable form (`[for value in …]`). The iterable
 * is a spec produced by {@see ForHeader}:
 *
 *   ['kind' => 'array', 'segments' => string[]]
 *   ['kind' => 'range', 'start' => Bound, 'end' => Bound, 'step' => ?Bound]
 *
 * where a Bound is ['type' => 'int', 'value' => int]
 *                or ['type' => 'ref', 'segments' => string[]].
 */
final class ForNode
{
    /**
     * @param array<string, mixed> $iterable
     * @param array<int, Token|IfNode|ForNode> $body
     */
    public function __construct(
        public readonly ?string $keyVar,
        public readonly string $valueVar,
        public readonly array $iterable,
        public readonly array $body,
        public readonly int $line,
        public readonly int $col,
    ) {
    }
}
