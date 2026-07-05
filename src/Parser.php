<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Turns the flat {@see Token} stream into a node tree.
 *
 * Leaf tokens (TEXT / SET / INTERP) pass through unchanged; COMMENT tokens are
 * dropped; IF / ELSEIF / ELSE / ENDIF tokens are assembled into {@see IfNode}s
 * and FOR / ENDFOR into {@see ForNode}s, with nesting handled by recursion.
 * `[break]` / `[continue]` are only valid inside a loop. The result is a list
 * of nodes the {@see Interpreter} can walk.
 */
final class Parser
{
    private int $i = 0;

    /** How many `[for]` loops enclose the node currently being parsed. */
    private int $loopDepth = 0;

    /**
     * Open block tags enclosing the current position, innermost last — so a
     * closer of the wrong kind can name the block it interrupts.
     *
     * @var array<int, array{tag: string, closer: string, line: int, col: int}>
     */
    private array $openBlocks = [];

    /** @param Token[] $tokens */
    private function __construct(
        private readonly array $tokens,
        private readonly ?string $file,
    ) {
    }

    /**
     * @param Token[] $tokens
     * @return array<int, Token|IfNode|ForNode>
     */
    public static function parse(array $tokens, ?string $file = null): array
    {
        return (new self($tokens, $file))->parseSequence([]);
    }

    /**
     * Parse nodes until a token whose type is in $stops (left unconsumed) or
     * the end of input.
     *
     * @param string[] $stops
     * @return array<int, Token|IfNode|ForNode>
     */
    private function parseSequence(array $stops): array
    {
        $nodes = [];

        while ($this->i < count($this->tokens)) {
            $tok = $this->tokens[$this->i];

            if (in_array($tok->type, $stops, true)) {
                return $nodes; // hand the terminator back to the caller
            }

            switch ($tok->type) {
                case Token::TEXT:
                case Token::SET:
                case Token::UNSET:
                case Token::INTERP:
                case Token::EXPR:
                    $nodes[] = $tok;
                    $this->i++;
                    break;

                case Token::COMMENT:
                    $this->i++; // renders to nothing
                    break;

                case Token::IF:
                    $nodes[] = $this->parseIf();
                    break;

                case Token::FOR:
                    $nodes[] = $this->parseFor();
                    break;

                case Token::BREAK:
                case Token::CONTINUE:
                    if ($this->loopDepth === 0) {
                        $this->fail($tok, "{$this->keyword($tok->type)} outside a [for] loop");
                    }
                    $nodes[] = $tok;
                    $this->i++;
                    break;

                case Token::ELSEIF:
                case Token::ELSE:
                case Token::ENDIF:
                case Token::ENDFOR:
                    // Inside an open block this is a closer of the wrong kind
                    // (e.g. [/for] while an [if] is open) — name that block,
                    // since the real mistake is usually its missing closer.
                    $open = end($this->openBlocks);
                    if ($open !== false) {
                        $this->fail($tok, "unexpected {$this->keyword($tok->type)} inside {$open['tag']}"
                            . " opened at {$open['line']}:{$open['col']} (missing {$open['closer']}?)");
                    }
                    $this->fail($tok, "unexpected {$this->keyword($tok->type)} without matching opener");

                default:
                    $this->fail($tok, "unexpected token {$tok->type}");
            }
        }

        return $nodes;
    }

    private function parseIf(): IfNode
    {
        $ifTok = $this->tokens[$this->i];
        $this->i++; // consume IF
        $this->openBlocks[] = ['tag' => '[if]', 'closer' => '[/if]', 'line' => $ifTok->line, 'col' => $ifTok->col];

        $branches = [];
        $body = $this->parseSequence([Token::ELSEIF, Token::ELSE, Token::ENDIF]);
        // Each branch keeps its own tag coordinates so a runtime error in an
        // [elseif] condition points at that [elseif], not the opening [if].
        $branches[] = ['cond' => $ifTok->value, 'body' => $body, 'line' => $ifTok->line, 'col' => $ifTok->col];

        while ($this->peekType() === Token::ELSEIF) {
            $elseifTok = $this->tokens[$this->i];
            $this->i++;
            $branches[] = [
                'cond' => $elseifTok->value,
                'body' => $this->parseSequence([Token::ELSEIF, Token::ELSE, Token::ENDIF]),
                'line' => $elseifTok->line,
                'col' => $elseifTok->col,
            ];
        }

        $else = null;
        if ($this->peekType() === Token::ELSE) {
            $this->i++;
            // Stop on a stray [else]/[elseif] too, so the misplacement gets a
            // pointed message instead of "without matching opener".
            $else = $this->parseSequence([Token::ENDIF, Token::ELSE, Token::ELSEIF]);
            if ($this->peekType() === Token::ELSE) {
                $this->fail($this->tokens[$this->i], 'duplicate [else] in [if]');
            }
            if ($this->peekType() === Token::ELSEIF) {
                $this->fail($this->tokens[$this->i], '[elseif] cannot follow [else]');
            }
        }

        if ($this->peekType() !== Token::ENDIF) {
            $this->fail($ifTok, 'unterminated [if] (missing [/if])');
        }
        $this->i++; // consume ENDIF
        array_pop($this->openBlocks);

        return new IfNode($branches, $else, $ifTok->line, $ifTok->col);
    }

    private function parseFor(): ForNode
    {
        $forTok = $this->tokens[$this->i];
        $this->i++; // consume FOR
        $this->openBlocks[] = ['tag' => '[for]', 'closer' => '[/for]', 'line' => $forTok->line, 'col' => $forTok->col];

        $this->loopDepth++;
        $body = $this->parseSequence([Token::ENDFOR]);
        $this->loopDepth--;

        if ($this->peekType() !== Token::ENDFOR) {
            $this->fail($forTok, 'unterminated [for] (missing [/for])');
        }
        $this->i++; // consume ENDFOR
        array_pop($this->openBlocks);

        $spec = $forTok->value;
        return new ForNode(
            $spec['keyVar'],
            $spec['valueVar'],
            $spec['iterable'],
            $body,
            $forTok->line,
            $forTok->col,
        );
    }

    private function peekType(): ?string
    {
        return $this->tokens[$this->i]->type ?? null;
    }

    private function keyword(string $type): string
    {
        return match ($type) {
            Token::ELSEIF => '[elseif]',
            Token::ELSE => '[else]',
            Token::ENDIF => '[/if]',
            Token::ENDFOR => '[/for]',
            Token::BREAK => '[break]',
            Token::CONTINUE => '[continue]',
            default => $type,
        };
    }

    private function fail(Token $tok, string $message): never
    {
        throw new SyntaxError($message, $tok->line, $tok->col, $this->file);
    }
}
