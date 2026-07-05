<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Thrown by {@see Environment} when a dot-path tries to descend through a
 * value that is already a scalar (e.g. `[set a = x]` then `[set a.b = y]`).
 * It carries no source coordinates; the {@see Interpreter} catches it at the
 * offending directive and re-raises a {@see SyntaxError} with line/col.
 */
final class PathConflict extends \RuntimeException
{
}
