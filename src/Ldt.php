<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Public facade for the ldt-lang (.ldt) engine — "Logic Driven Text Language".
 *
 *   echo Ldt::render('Hello [set who = world][= @who]!');
 *   echo Ldt::renderFile('examples/assignments.ldt');
 */
final class Ldt
{
    /**
     * Render .ldt source text to its output string.
     *
     * When $trim is true (default) standalone directive lines are removed so a
     * `[set]` or `[# #]` on its own line does not leave a blank line — see
     * {@see Trimmer}.
     */
    /**
     * @param array<int|string, mixed> $data initial variables (the render
     *        context); scalars are stringified and nested arrays are addressed
     *        by dot-path. Inline `[set]` may override these.
     */
    public static function render(string $source, array $data = [], bool $strict = false, ?string $file = null, bool $trim = true): string
    {
        $tokens = Lexer::tokenize($source, $file);
        if ($trim) {
            $tokens = Trimmer::trim($tokens);
        }
        $nodes = Parser::parse($tokens, $file);

        $env = new Environment();
        if ($data !== []) {
            $env->seed($data);
        }
        return (new Interpreter($strict, $file, $trim))->render($nodes, $env);
    }

    /**
     * Render a .ldt file from disk.
     *
     * @param array<int|string, mixed> $data initial variables (see {@see render}).
     */
    public static function renderFile(string $path, array $data = [], bool $strict = false, bool $trim = true): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("file not found: $path");
        }
        $source = (string) file_get_contents($path);
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3); // a leading UTF-8 BOM is an editor artifact, not content
        }
        return self::render($source, $data, $strict, $path, $trim);
    }

    /**
     * Tokenize source without rendering — useful for tooling and debugging.
     *
     * @return Token[]
     */
    public static function tokens(string $source, ?string $file = null): array
    {
        return Lexer::tokenize($source, $file);
    }
}
