<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Whitespace control: removes "standalone" directive lines.
 *
 * A line is standalone when its only content is directives plus whitespace —
 * no literal text and no `@{...}`. Such a line loses its leading indentation
 * and its trailing newline, so a `[set]` or `[# #]` written on its own line
 * does not leave a blank line behind. A line that also carries real text or an
 * interpolation is left untouched, which keeps inline embedding exactly as
 * written.
 *
 * Runs as a pass between the {@see Lexer} and {@see Parser}. Newlines live only
 * inside TEXT tokens, so the pass splits TEXT on newlines, decides each line,
 * and reassembles the token stream.
 */
final class Trimmer
{
    /** No-output / structural directives that let a line be standalone. */
    private const TRIMMABLE = [
        Token::SET, Token::UNSET, Token::COMMENT,
        Token::IF, Token::ELSEIF, Token::ELSE, Token::ENDIF,
        Token::FOR, Token::ENDFOR, Token::BREAK, Token::CONTINUE,
    ];

    /**
     * @param Token[] $tokens
     * @return Token[]
     */
    public static function trim(array $tokens): array
    {
        return self::toTokens(self::stripStandaloneLines(self::toAtoms($tokens)));
    }

    /**
     * Explode the token stream into atoms so newlines become explicit:
     * ['kind' => 'text'|'nl'|'tok', ...].
     *
     * @param Token[] $tokens
     * @return array<int, array<string, mixed>>
     */
    private static function toAtoms(array $tokens): array
    {
        $atoms = [];
        foreach ($tokens as $t) {
            if ($t->type !== Token::TEXT) {
                $atoms[] = ['kind' => 'tok', 'token' => $t];
                continue;
            }
            // Track line/col through the parts so every atom carries its own
            // exact position (a reassembled TEXT token starts at its first
            // atom's coordinates).
            $parts = explode("\n", $t->value);
            $line = $t->line;
            $col = $t->col;
            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    $atoms[] = ['kind' => 'nl', 'line' => $line, 'col' => $col];
                    $line++;
                    $col = 1;
                }
                if ($part !== '') {
                    $atoms[] = ['kind' => 'text', 's' => $part, 'line' => $line, 'col' => $col];
                }
                $col += strlen($part);
            }
        }
        return $atoms;
    }

    /**
     * @param array<int, array<string, mixed>> $atoms
     * @return array<int, array<string, mixed>>
     */
    private static function stripStandaloneLines(array $atoms): array
    {
        $out = [];
        $line = [];

        // $nl is the newline atom that ended the line (null on the final,
        // unterminated line); passing it through keeps its coordinates.
        $flush = static function (?array $nl) use (&$out, &$line): void {
            if (self::lineHasDirective($line) && !self::lineHasContent($line)) {
                // Standalone: keep the directives, drop padding and the newline.
                foreach ($line as $a) {
                    if ($a['kind'] === 'tok') {
                        $out[] = $a;
                    }
                }
            } else {
                foreach ($line as $a) {
                    $out[] = $a;
                }
                if ($nl !== null) {
                    $out[] = $nl;
                }
            }
            $line = [];
        };

        foreach ($atoms as $a) {
            if ($a['kind'] === 'nl') {
                $flush($a);
            } else {
                $line[] = $a;
            }
        }
        $flush(null); // final line has no terminating newline

        return $out;
    }

    /** @param array<int, array<string, mixed>> $line */
    private static function lineHasContent(array $line): bool
    {
        foreach ($line as $a) {
            if ($a['kind'] === 'text' && preg_match('/\S/', $a['s']) === 1) {
                return true;
            }
            if ($a['kind'] === 'tok' && in_array($a['token']->type, [Token::INTERP, Token::EXPR], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, array<string, mixed>> $line */
    private static function lineHasDirective(array $line): bool
    {
        foreach ($line as $a) {
            if ($a['kind'] === 'tok' && in_array($a['token']->type, self::TRIMMABLE, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $atoms
     * @return Token[]
     */
    private static function toTokens(array $atoms): array
    {
        $out = [];
        $buf = '';
        $bufLine = 1;
        $bufCol = 1;
        $open = false;

        foreach ($atoms as $a) {
            if ($a['kind'] === 'tok') {
                if ($buf !== '') {
                    $out[] = new Token(Token::TEXT, $buf, $bufLine, $bufCol);
                    $buf = '';
                    $open = false;
                }
                $out[] = $a['token'];
                continue;
            }

            if (!$open) {
                $bufLine = $a['line'] ?? 1;
                $bufCol = $a['col'] ?? 1;
                $open = true;
            }
            $buf .= $a['kind'] === 'nl' ? "\n" : $a['s'];
        }

        if ($buf !== '') {
            $out[] = new Token(Token::TEXT, $buf, $bufLine, $bufCol);
        }
        return $out;
    }
}
