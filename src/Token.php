<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * A single lexical token produced by the {@see Lexer}.
 *
 * The stream is intentionally flat — a parser consumes it and builds a tree;
 * the lexer contract stays the same.
 */
final class Token
{
    /** Literal passthrough text. `value` is the raw string. */
    public const TEXT = 'TEXT';

    /** A `[# ... #]` comment that renders to nothing. `value` is the body. */
    public const COMMENT = 'COMMENT';

    /**
     * A `[set path]value[/set]` or `[set path = value]` directive.
     * `value` is an array:
     *   ['segments' => string[], 'append' => bool, 'expr' => string]
     * `segments` is the dot-path split into parts (un-normalized); `append` is
     * true when the target ended in a trailing dot (`fruit.` → push next index).
     */
    public const SET = 'SET';

    /**
     * An `[= expr]` emit tag — evaluates the expression (filters allowed) and
     * writes the result into the output. `value` is the parsed {@see Expr}.
     */
    public const EMIT = 'EMIT';

    /**
     * An `[unset a, b.c]` directive — removes each path entirely (undefined).
     * `value` is ['paths' => array<int, string[]>] (a list of segment lists).
     */
    public const UNSET = 'UNSET';

    /** `[if <condition>]`. `value` is the parsed condition {@see Expr}. */
    public const IF = 'IF';

    /** `[elseif <condition>]`. `value` is the parsed condition {@see Expr}. */
    public const ELSEIF = 'ELSEIF';

    /** `[else]`. `value` is null. */
    public const ELSE = 'ELSE';

    /** `[/if]`. `value` is null. */
    public const ENDIF = 'ENDIF';

    /** `[for <header>]`. `value` is the header spec from {@see ForHeader}. */
    public const FOR = 'FOR';

    /** `[/for]`. `value` is null. */
    public const ENDFOR = 'ENDFOR';

    /** `[break]`. `value` is null. */
    public const BREAK = 'BREAK';

    /** `[continue]`. `value` is null. */
    public const CONTINUE = 'CONTINUE';

    public function __construct(
        public readonly string $type,
        public readonly mixed $value,
        public readonly int $line,
        public readonly int $col,
    ) {
    }
}
