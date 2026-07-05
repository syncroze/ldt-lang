<?php

declare(strict_types=1);

namespace Ldtlang;

/**
 * Holds variable state during a render.
 *
 * A value is either a scalar string or a (possibly nested) array. Dot-paths
 * address into that structure to any depth:
 *
 *   greeting            → root scalar
 *   user.first          → vars['user']['first']
 *   fruit.  (append)    → push onto the array at vars['fruit']
 *   fruit.0.  (append)  → push onto the array at vars['fruit'][0]
 *   fruit.first.child   → vars['fruit']['first']['child']
 *
 * A purely numeric segment is normalized to an int index (so `.1` and an
 * appended slot 1 collide, matching PHP's native array keying). Descending
 * *through* a scalar is a type conflict and throws {@see PathConflict}; the
 * {@see Interpreter} catches it and re-raises a {@see SyntaxError} with the
 * directive's coordinates.
 */
final class Environment
{
    /** @var array<int|string, mixed> */
    private array $vars = [];

    /**
     * Seed the environment from an external data array (the render context).
     * Scalars are stringified (bool → "1"/"0", null → ""), nested arrays are
     * kept as nested containers, so the dot-path model addresses into them. An
     * inline `[set]` may still override a seeded value later.
     *
     * @param array<int|string, mixed> $data
     */
    public function seed(array $data): void
    {
        $this->vars = self::normalizeArray($data, '');
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private static function normalizeArray(array $data, string $path): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::normalizeValue($value, $path === '' ? (string) $key : "$path.$key");
        }
        return $out;
    }

    private static function normalizeValue(mixed $value, string $path): string|array
    {
        if (is_array($value)) {
            return self::normalizeArray($value, $path);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0'; // a boolean is an answer, so false is 0
        }
        if ($value === null) {
            return ''; // null is the absence of an answer, so it stays empty
        }
        if (is_float($value) && !is_finite($value)) {
            // INF/NAN stringify to "INF"/"NAN" — words, not Numbers; reject
            // them at the boundary like any other unrepresentable value.
            // (Named explicitly: coercing NAN to string raises a PHP warning.)
            $label = is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
            throw new \InvalidArgumentException(
                "seeded floats must be finite; got $label at key '$path'"
            );
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        throw new \InvalidArgumentException(
            'seeded values must be scalars, null or arrays; got ' . get_debug_type($value) . " at key '$path'"
        );
    }

    /**
     * Assign $value at the dot-path $segments. When $append is true the path
     * addresses an array and the value is pushed at the next integer index.
     * Intermediate containers are auto-created; a scalar in the way is an error.
     *
     * @param array<int, string> $segments
     */
    public function assign(array $segments, bool $append, string $value): void
    {
        $ref = &$this->vars;

        if ($append) {
            foreach ($segments as $seg) {
                $ref = &$this->descend($ref, $seg);
            }
            $ref[] = $value;
            return;
        }

        $last = array_pop($segments);
        foreach ($segments as $seg) {
            $ref = &$this->descend($ref, $seg);
        }
        $ref[self::normalizeKey($last)] = $value;
    }

    /**
     * Resolve a dot-path to a scalar. Returns null when any segment is missing,
     * when the path passes through a scalar, or when it lands on an array
     * (arrays are not directly renderable).
     *
     * @param array<int, string> $segments
     */
    public function lookup(array $segments): ?string
    {
        $raw = $this->lookupRaw($segments);
        return is_string($raw) ? $raw : null;
    }

    /**
     * Resolve a dot-path to its raw value — a scalar string, an array, or null
     * when any segment is missing or the path passes through a scalar. Used by
     * `[for]` to reach the array (or sub-array) it iterates.
     *
     * @param array<int, string> $segments
     * @return string|array<int|string, mixed>|null
     */
    public function lookupRaw(array $segments): string|array|null
    {
        $ref = $this->vars;
        foreach ($segments as $seg) {
            $key = self::normalizeKey($seg);
            if (!is_array($ref) || !array_key_exists($key, $ref)) {
                return null;
            }
            $ref = $ref[$key];
        }
        return $ref;
    }

    /**
     * Bind a top-level name to a value (scalar or array). Used to scope `[for]`
     * loop variables; the value may be a sub-array so nested iteration works.
     *
     * @param string|array<int|string, mixed> $value
     */
    public function bind(string $name, string|array $value): void
    {
        $this->vars[$name] = $value;
    }

    /**
     * Capture a top-level name's current binding so `[for]` can restore it
     * after the loop (block scoping for the loop variables).
     *
     * @return array{set: bool, value?: string|array<int|string, mixed>}
     */
    public function snapshot(string $name): array
    {
        return array_key_exists($name, $this->vars)
            ? ['set' => true, 'value' => $this->vars[$name]]
            : ['set' => false];
    }

    /** @param array{set: bool, value?: string|array<int|string, mixed>} $snap */
    public function restore(string $name, array $snap): void
    {
        if ($snap['set']) {
            $this->vars[$name] = $snap['value'];
        } else {
            unset($this->vars[$name]);
        }
    }

    /**
     * Element count of the array at a dot-path, for `count @path`. An undefined
     * path counts as 0 (an empty collection); a path that lands on a scalar
     * returns null so the caller can report "not an array".
     *
     * @param array<int, string> $segments
     */
    public function sizeOf(array $segments): ?int
    {
        $raw = $this->lookupRaw($segments);
        if ($raw === null) {
            return 0;
        }
        return is_array($raw) ? count($raw) : null;
    }

    /**
     * Existence test for a dot-path (true even when it lands on an array).
     * Stored values are never null ({@see seed} normalizes null to ''), so a
     * null lookup can only mean "missing".
     */
    public function defined(array $segments): bool
    {
        return $this->lookupRaw($segments) !== null;
    }

    /**
     * Remove the value at a dot-path entirely — the name (or key) becomes
     * undefined, subtree included. Silently a no-op when any part of the path
     * is missing or passes through a scalar (idempotent, like PHP unset()).
     *
     * @param array<int, string> $segments
     */
    public function remove(array $segments): void
    {
        $last = array_pop($segments);
        $ref = &$this->vars;
        foreach ($segments as $seg) {
            $key = self::normalizeKey($seg);
            if (!array_key_exists($key, $ref) || !is_array($ref[$key])) {
                return; // nothing to remove
            }
            $ref = &$ref[$key];
        }
        unset($ref[self::normalizeKey($last)]);
    }

    /**
     * Return a reference to the array living at $seg within $container,
     * creating it if absent. Throws when a scalar occupies that slot.
     *
     * @param array<int|string, mixed> $container
     * @return array<int|string, mixed>
     */
    private function &descend(array &$container, string $seg): array
    {
        $key = self::normalizeKey($seg);
        if (!array_key_exists($key, $container)) {
            $container[$key] = [];
        } elseif (!is_array($container[$key])) {
            throw new PathConflict("cannot descend into scalar '$seg'");
        }
        return $container[$key];
    }

    /** A purely numeric segment is treated as an integer index. */
    private static function normalizeKey(string $key): int|string
    {
        return preg_match('/^-?\d+$/', $key) === 1 ? (int) $key : $key;
    }

    /**
     * Split and validate a dot-path (`name`, `user.first`, `items.0`), or null
     * when malformed. The path syntax lives here, next to the key semantics,
     * so every parser (Lexer, ExprParser, ForHeader) validates identically.
     *
     * @return array<int, string>|null
     */
    public static function segmentsOf(string $raw): ?array
    {
        if ($raw === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.(?:[A-Za-z_][A-Za-z0-9_]*|-?\d+))*$/', $raw) !== 1) {
            return null;
        }
        return explode('.', $raw);
    }
}
