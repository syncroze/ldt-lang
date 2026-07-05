<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Internal control-flow signal thrown by `[continue]` and caught by the nearest
 * enclosing `[for]` in {@see Interpreter}. Never escapes a render.
 */
final class ContinueSignal extends \Exception
{
}
