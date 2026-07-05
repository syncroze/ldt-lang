# ldt-lang caveats

The complete list of edge cases and "gotchas" to be aware of. Most are inherent
to a delimiter-based embeddable language; each has a clear workaround. For a
runnable escaping demo see `examples/limitations.ldt`.

---

## 1. Reference forms are context-exclusive

There are two ways to name a variable, and they do **not** overlap:

| Context | Form | A bare `@` is… |
|---|---|---|
| Body text and `[set]` values | `@{path}` only | **literal** (so `me@site.com` is left intact) |
| Expressions — `[if]`, `[for]`, `@()` | bare `@path` only | the reference |

- **A bare `@name` in body text does *not* interpolate** — it prints literally.
  Use `@{name}` to interpolate in text.
  ```
  value @a here      →  value @a here      (literal)
  value @{a} here    →  value X here       (interpolated)
  ```
- **The closed `@{name}` is rejected inside an expression** — use bare `@name`.
  ```
  [if @{role}]…      →  error: "use a bare @name inside an expression"
  [if @role]…        →  ok
  ```

## 2. Writing a delimiter literally needs `\`

Every construct's opener/closer is meaningful, so to print one literally, escape
it. The rule: **`\` before a non-alphanumeric character emits that character
literally**; before a letter, digit, or end-of-line the `\` is itself literal
(so `C:\Users` and ordinary prose backslashes survive).

| To print | Write |
|---|---|
| `@{` (interpolation) | `\@{` |
| `@(` (expression) | `\@(` |
| a tag like `[set …]`, `[if …]`, `[# …` | `\[set …]`, `\[if …]`, `\[#` |
| a `]` in an unquoted `=` value, or `[/set]` in a block value | `\]`, `\[/set]` |
| a comment closer `#]` (inside a comment) | `\#]` |
| a literal backslash | `\\` |

## 3. Escapes in expressions live *inside* quoted strings

Bare `\` escapes don't exist in `[if]`, `[for]`, or `@(...)` — but **inside a
`"..."` string literal the universal escape rule applies** (`\` before a
non-alphanumeric char yields it; before a letter, digit or end-of-line it
stays literal), and strings may span newlines:

```
[if @x == "a]b"]…             quotes hold the special character
[if @x == "say \"hi\""]…      an escaped quote inside the string
[if @p == "C:\Users"]…        backslash before a letter is literal
```

## 4. A value/comment can't contain its own closer unescaped

- A block `[set x]…[/set]` value stops at the **first** literal `[/set]` —
  unless the body is a quoted whole value, which protects it:
  `[set x]"a [/set] b"[/set]` needs no escape.
- A self-closing `[set x = …]` value stops at the **first** unescaped `]`
  (quotes protect it: `[set x = "a ] b"]` needs no escape).
- A `[# … #]` comment stops at the **first** literal `#]`. **Quoting does not
  protect it** (quotes mean nothing in comments) — escape it as `\#]`.
- The `#` cannot be shared between opener and closer: `[#]` is an
  *unterminated* comment. The minimal comment is `[##]`.

Escape the closer to include it literally (see §2).

---

## 4b. Quoted `[set]` values — the strict rule

- Set values are **trimmed at the ends** (both forms). To keep edge spaces,
  quote the whole value: `[set pad = "   x   "]` stores `   x   `.
- A value **starting with an unescaped `"`** must be a *properly quoted whole
  value*: the closing `"` is the last character and interior quotes are
  escaped (`\"`). Otherwise it is an error:
  ```
  [set a = "yes" or "no"]     → error — write "yes\" or \"no"
  [set q]"[/set]               → error — a lone quote is \"
  ```
- To store literal surrounding quotes, escape the opening one: `\"...\"`.

- Quotes elsewhere in a value stay literal: `[set s = say "hi" now]` is fine.

## 4c. `[unset]` removes; it never empties

- `[unset path]` (or `[unset a, b.c]`) makes the path **undefined** — not an
  empty value: `defined` → 0, strict mode errors, fallbacks catch it.
- Idempotent: unsetting a missing path (or one passing through a scalar) is a
  silent no-op.
- Removing an array **index** leaves a hole — no reindexing. `count` drops,
  the remaining keys keep their positions, and the next append continues past
  the old maximum (`0,1,2` → unset `1` → append lands on `3`). Negative
  indexes are valid keys (`[set a.-1 = v]`); an append past a lone negative
  key starts at `0` (PHP's max-int-key-plus-one rule).
- Removing the **last** key leaves a defined-but-empty array: `defined` → 1,
  but `[if @a]` is false and `count @a` is 0 (empty-array falsy rule).
- **Assigning a scalar over a map replaces it wholesale** —
  `[set a.b = 1][set a = x]` silently drops the whole subtree (`a.b` becomes
  undefined). Only the other direction is loud: *descending into* a scalar
  (`[set a = x][set a.b = 1]`) is a `cannot descend into scalar` error.
- Reset-a-container idiom: `[unset g][set g.k = v]` (a plain
  `[set g = ]` would make `g` a scalar and later `g.k` sets would error).

## 4d. Block values are mini-templates — the boundaries

- A `[set]` value renders exactly like body text, with two boundaries:
  a `[break]`/`[continue]` inside a value binds only to a `[for]` *inside the
  same value* — it cannot break a loop outside it; and a nested *block* set
  errors loudly (self-closing sets nest fine).
- Errors inside values report **exact template coordinates** (the value's
  re-lex is seated at its real position).
- Whitespace inside any tag *header* includes newlines — long `[for]`/`[set]`/
  `[unset]` headers may wrap across lines. Value regions are unaffected.
- The argument-less markers (`[else]`, `[/if]`, `[/for]`, `[break]`,
  `[continue]`) accept whitespace before their `]` too — `[else ]` is the tag,
  `[else x]` stays literal. The value/comment closers `[/set]` and `#]` remain
  exact sentinels: no interior whitespace.

## 5. Expressions `@(...)` are integer-only

- Only **integers** are accepted. A non-integer operand — including a float
  *literal* like `5.0` — is an error:
  ```
  @(5.0 + 1)         →  error: "arithmetic requires integer operands, got '5.0'"
  ```
- `/` is **integer division** (truncates toward zero): `@(10 / 3)` → `3`.
- Division or modulo by zero is an error: `@(5 / 0)`, `@(5 % 0)`.
- Float math is not supported (yet). Compose results into variables and keep
  everything integer.

## 5b. `count @array` is array-length only

- `count @path` returns the number of elements: **undefined → `0`**, a scalar →
  **error** (`"cannot count @x: not an array"`), same spirit as `[for]` over a
  non-array. It is not string length.
- `count` and `defined` are **context-sensitive**, not reserved (like the
  substring operators): they act as operators only when followed by a
  `@reference`, so `[if @x == count]` compares against the literal word.

## 5c. Filters

- **Filters work only in `@{}` and `@()`** — in an `[if]`/`[for]` tag condition
  a `|` is an error ("filters are not supported in a tag condition"). Filter
  into a `[set]` first, or use `@( ... )`.
- **`|`, `:` and `,` are now expression tokens.** An unquoted bareword cannot
  contain them — quote such literals (`[if @t == "10:30"]`, `join: ", "`).

- **Filter args are full expressions**, so inside them `-` is subtraction and
  barewords with hyphens/commas must be quoted (same rule as `@()`).
- **A plain `@ref` argument reads the raw value** (like the chain input), so
  an array can be a `default:` fallback:
  `@{list | default: @fallbackList | join: ", "}`. Scalar-expecting arguments
  (`truncate` / `round` / `join`) reject an array argument loudly.
- **`default:` evaluates its argument lazily** — only when the fallback
  actually fires. An error inside an unused fallback (say, a division by
  zero) never surfaces; every other filter needs its arguments and evaluates
  them eagerly.
- **The final chain result must be a scalar.** Arrays may flow *through* the
  chain (to `join`/`first`/`last`), but an array at the end is an error.
- **`default` vs `--strict`:** a `default` filter satisfies strict mode for
  an undefined path; any other chain on an undefined path still errors under
  `--strict`.
- **Filter arity is enforced** — extra or missing arguments are errors
  (`filter 'upper' takes no arguments`).
- Filter names are only reserved **after a `|`** — no new global reserved words.
- **Case filters are ASCII-only**: `upper` / `lower` / `capitalize` transform
  `a–z` / `A–Z` only; every other byte passes through (`é` stays `é`) — the
  same dependency-free byte policy as the substring operators.
- **`truncate` counts bytes but never splits a UTF-8 character**: when the
  cut lands inside a multi-byte sequence, the incomplete tail is dropped
  before the suffix is added (a complete character at the edge is kept).
  Assumes UTF-8 text — a stray Latin-1 high byte at the cut is dropped too.
- **`round` / `abs` are float-based**: beyond ~15 significant digits PHP float
  precision applies, and a huge result renders in scientific notation
  (`1.23…E+29`) — which is then *not* a Number by the text rule, so later
  arithmetic on it errors. Keep filter math at ordinary magnitudes; `@()`
  integer arithmetic is exact within the platform integer range.

## 5d. Substring operators

- `contains` / `starts with` / `ends with` are **case-sensitive** and byte-wise
  (like string `==`). For a case-insensitive test, lowercase first:
  `[set e = @{email | lower}][if @e contains …]`.
- They are **context-sensitive**, not reserved: only recognized in operator
  position, so `[if @x == contains]` still compares against the literal word.
- An **empty needle is always found** (`@x contains ""` is true), matching PHP.
- `starts` / `ends` **require** the `with` (`starts "x"` is an error).

## 6. Inside `@()`, `-` is subtraction

Because arithmetic is on inside `@(...)`, a hyphen is the subtract operator, so an
unquoted bareword with a hyphen is parsed as arithmetic and fails. Quote it:

```
@(@role == super-admin)      →  error (parsed as super − admin)
@(@role == "super-admin")    →  ok
```

The same trade-off hits a **negative array index**: `@a.-1` works in `[if]`
and as `@{a.-1}` in text, but inside `@(...)` the `-` reads as subtraction, so
`@(@a.-1)` is an error. Read it outside the expression first
(`[set x = @{a.-1}]`).

(Plain `[if]`/`[for]` conditions do **not** enable arithmetic, so hyphenated
barewords like `[if @x == some-value]` keep working there unchanged.)

## 7. `@(...)` cannot be nested, and cannot be a range bound

- **No nested `@()`** — use plain grouping `( )` instead:
  ```
  @(@(1 + 1) + 1)    →  error
  @((1 + 1) + 1)     →  ok
  ```
- **A range bound cannot be an `@()` expression** — bounds are integer literals
  or bare `@refs`. Compute first, then use the variable:
  ```
  [for n in 1 to @(@x + 1)]…            →  error
  [set hi = @(@x + 1)][for n in 1 to @hi]…   →  ok
  ```

## 8. `--strict` guards only `@{path}` interpolations

`--strict` makes an undefined `@{path}` interpolation an error (unless its
chain has a `default:`). Everywhere else undefined is a **value**, not an
error — conditions and loops are the existence tests, so they stay silent by
design:

```
--strict  x@{missing}y        →  error: undefined reference
--strict  [if @missing]…      →  takes the false branch (no error)
--strict  [for v in @missing] →  zero iterations (no error)
--strict  x@(@missing)y       →  xy   (ref → empty; using it in arithmetic
                                       still fails: "…got ''")
```

A *defined* value never errors under strict either — including an array
rendered directly: `@{arr}` gives `''` (arrays are not renderable; add a
`join` or iterate).

Filter **arguments** are expressions, so they are never strict-guarded —
including a `default:` fallback: `@{missing | default: @alsoMissing}` renders
empty under `--strict`, with no error (the chain has a `default:`, and the
undefined fallback reads as `''`).

The guard follows `@{}` wherever it appears — an undefined interpolation
inside a `[set]` value errors too, at its exact template coordinates.

---

## 9. Data types: a value is String or Number by its text

A value is treated as a **Number** when its text matches `[+-]?\d+(\.\d+)?`,
otherwise a **String**. The classification is used for comparison and
truthiness; the original text is always preserved on output (`+5` renders as
`+5`, compares as `5`).

- `007` **renders** as `007` but **compares** as `7`:
  ```
  [set id = 007]@{id} but @(@id == 7)     →  007 but 1
  ```
- Comparison is type-aware: both operands numeric → numeric (`5 == 5.0`),
  otherwise lexicographic (`apple < banana`).
- **Falsy** = empty string, undefined, and numeric zero (`0`, `0.0`, `-0`,
  `+0`). Everything else is truthy — including a non-numeric `"0x"`.
- **Booleans textualize as `1` / `0`** — a comparison result stored or emitted
  is a Number (`[set f = @(1 > 2)]@{f}` → `0`), so `@((…) == 0)` works.

## 9b. One falsy rule: `[if]` and `default:` agree

- The fallback (`| default: "F"`) triggers exactly when `[if @x]` would be
  false: undefined, empty string, numeric zero, or an empty array. Its
  argument is a full expression (`| default: @fb`), and chained defaults
  cascade through falsy values.
- Consequence: **a genuine `0` cannot survive an attached fallback** —
  `[set n = 0]@{n | default: "-"}` renders `-`. To display a zero, don't attach a
  fallback (or guard with `[if defined @n]`).
- `007` (numeric 7) and `"0x"` (non-numeric) are truthy and pass through.
- A bare reference to a **non-empty array** is truthy in `[if]` / `not` /
  `and` / `or` (an empty array is falsy) — the same rule `default:` applies,
  so the two always agree here too. In a **comparison** (or as text) an array
  still reads as `''`; test presence with `[if @items]`, size with
  `count @items`.
- Strict mode still errors only on **undefined** without a fallback; a
  defined-but-falsy value never errors.

## 10. Loops

- **A bare integer is not iterable** — a range needs `to`: `[for n in 5]` errors;
  `[for n in 1 to 5]` is the range.
- **Iterating records:** when a loop value is itself a sub-array, `@{v}` renders
  empty (an array isn't directly renderable) but `@{v.field}` resolves.
- **Loop variables are block-scoped** — they don't leak, and any prior binding of
  the same name is restored after the loop.
- **`[break]` / `[continue]` bind to the nearest enclosing loop**, even when
  nested inside `[if]`s.
- **`loop` is bound inside every `[for]` body** (for `@{loop.index}` etc.). A
  variable of your own named `loop` is shadowed inside the loop and restored
  after it — same block-scoping as the loop variables.
- **`loop` cannot be a loop *variable* name** — `[for loop in …]` (or
  `[for k, loop in …]`) is an error: the metadata binding would make the
  value unreachable. Pick another name; only a pre-existing variable named
  `loop` is silently shadowed (previous bullet).
- **A range's keys are 0-based positions** — `[for k, n in 5 to 7]` binds
  `k` to `0, 1, 2` (a range behaves like a list), while `@{loop.index}` stays
  1-based. With an array, `k` is the array's own key.
- **An undefined range *bound* is an error** — unlike `[for v in @missing]`
  (zero iterations, see §8), `[for n in 1 to @missing]` fails loudly: bounds
  must be integers, and "no integer" is not a usable bound.
- **Range bounds must fit the platform integer** — a literal or `@ref` bound
  beyond `PHP_INT_MAX`/`PHP_INT_MIN` is a loud error (PHP would otherwise
  silently saturate it). Ranges ending exactly *at* the limits work.
- **Ranges stream lazily** — `[for n in 1 to 1000000000]` costs time, never
  memory: values are produced one at a time and `loop.count` is computed
  arithmetically. (Only the loop's own *output* occupies memory.)

## 11. Whitespace trimming

A line whose only content is directives/comments (no text, no `@{}`/`@()`) is
**standalone**: it's removed along with its trailing newline, so a tag on its own
line leaves no blank line. A line that also has real text or an interpolation is
left untouched. Pass `--no-trim` to disable this.

- **Escaped whitespace does not protect a line** — by the time the trimmer
  decides, `\ ` (escaped space) is plain whitespace text, so a directive line
  padded only with escaped spaces still trims. To keep a line, give it visible
  text or an `@{}`.
- **Only `\n` ends a line.** CRLF files work naturally (the `\r` trims away
  with its line), but CR-only line endings (classic Mac) are not line endings:
  such a file is one long line — nothing trims, and the `\r`s render through
  as ordinary text.

## 12. Seeded data

- Scalars from a data context are **stringified**: `true` → `"1"`,
  `false` → `"0"` (an answer), `null` → `""` (the absence of one). All of
  `false`/`null` remain falsy; `false` never becomes the string `"false"`.
- An inline `[set]` **overrides** a seeded value; via the CLI, a later `--set` /
  `--json` overrides an earlier one. `--json` merging is map-wise: objects
  deep-merge key by key, but a JSON **list** replaces the previous value
  wholesale (overriding `["a","b","c"]` with `["x"]` yields `["x"]` — no
  leftover tail).
- `--set a.b=c` builds the nested path `a.b`; the value is always a string.
  Keys are validated as dot-paths (`--set 'a b=c'` is a CLI error). Keys in
  seeded PHP arrays are the host app's responsibility — invalid ones are
  unreachable from templates.
- **A purely numeric path segment is an integer index** — `.01`, `.1` and an
  appended slot `1` all address the same key (PHP's array keying). A seeded
  **string** key like `'01'` (which PHP keeps as a string) is therefore
  unreachable from templates: `@{a.01}` reads the int key `1` and misses.
- **A seeded `null` is an empty *scalar*, not an absent key** — descending
  into it (`[set a.b = x]` with `['a' => null]`) is a path conflict. Omit
  the key entirely to let the template build the container.
- Seeded **values** must be scalars, null or arrays — anything else (objects,
  resources) throws `InvalidArgumentException` naming the offending key path.
  Non-finite floats (`INF` / `NAN`) are rejected the same way; an ordinary
  float seeds by its PHP string form, which beyond ~15 significant digits is
  scientific notation (`1.0E+20`) — no longer a Number by the text rule.
- **CLI seeding is later-wins, wholesale**: `--set a=x --set a.c=2` silently
  rebuilds `a` as a map (the scalar is dropped). Inline `[set]` is a template
  mutation and errors on the same conflict (`cannot descend into scalar`) —
  seeding describes a desired end state; the template protects its own.

## 13. Resource limits

Templates are the host app's own files — trusted input. There is deliberately
no nesting-depth or iteration cap: a pathological template (e.g. ~100,000
nested `[if]`s) eventually hits PHP's own stack/memory limits as a fatal
error rather than a `SyntaxError`. Realistic nesting (tested to 20,000 deep)
and arbitrarily long-running range loops are fine.

---

## Not a caveat anymore (resolved)

These earlier limitations were removed by later design changes and are listed
only to avoid confusion:

- *Mid-word interpolation* — `@{x}` has a clear close, so `con@{x}tetur` works.
- *Range start could not be a `@ref`* — fixed by the `to`/`by` keywords
  (`@lo to @hi by @step`).
- *A literal `[` before an interpolation* — no longer relevant; interpolation is
  `@{…}`, not the old `[[…]]`.
