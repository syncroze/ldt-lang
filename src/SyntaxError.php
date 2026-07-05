<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Raised when a .ldt source cannot be tokenized or resolved (e.g. an
 * unterminated `[set]` directive, a malformed `@path` reference, or a
 * dot-path that descends into a scalar). Carries source coordinates so the
 * CLI can point at the offending spot.
 *
 * Note: \Exception already declares $line/$file, so the source coordinates
 * are exposed under distinct names ($srcLine, $srcCol, $srcFile).
 */
final class SyntaxError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $srcLine,
        public readonly int $srcCol,
        public readonly ?string $srcFile = null,
    ) {
        $where = ($srcFile ?? '<source>') . ":$srcLine:$srcCol";
        parent::__construct("$where: $message");
    }
}
