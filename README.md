# ldt-lang

**Logic Driven Text Language** — an embeddable templating language interpreted
in PHP. Like PHP, it is written *inside* arbitrary text: everything is literal
until a bracket construct appears. Files use the `.ldt` extension.

The engine is a four-stage `Lexer → Trimmer → Parser → Interpreter` pipeline
with a bracket-tag surface syntax and a **nested** data model.

## Data model — two types, nested arrays, undefined

A value is classified by its *text*:

| Type | Rule | Examples |
|------|------|----------|
| **Number** | text matches `[+-]?digits(.digits)?` | `5`, `-3`, `+5`, `0.5`, `007` |
| **String** | everything else | `hi`, `on`, `0x`, `3px` |
| **Array** | built by dot-path sets or seeded data; nests to any depth | `items.0`, `user.first` |
| **undefined** | a name never set — ldt's "null" | renders empty, is falsy |

The classification is a lens for comparison and truthiness — **the original
text is always preserved on output**:

```
[set id = 007]@{id} renders verbatim, but compares as a number: @(@id == 7)
→ 007 renders verbatim, but compares as a number: 1
```

**Falsy** = empty string, undefined, numeric zero (`0`, `0.0`, `-0`), and an
empty array. Everything else is truthy — including the non-numeric string
`0x` and a non-empty array (`[if @items]` tests presence; `count @items` its
size; in a comparison an array still reads as `''`).

## Assignment & interpolation

### Set a variable

```
[set greeting]Hello[/set]        block form — the body is the value (spaces kept)
[set name = world]               self-closing form — value runs to ] (trimmed)
```

Values are trimmed at the ends in both forms. To keep leading/trailing spaces,
**quote the whole value** — the outer quotes are stripped and the inside kept
verbatim:

```
[set pad = "   hello   "]       stores «   hello   » (no quotes)
[set say = "she said \"hi\""]   interior quotes escaped as \"
[set lit = \"quoted\"]          escaped leading quote → stores «"quoted"»
```

A value that *starts* with an unescaped `"` must be a properly quoted whole
value (closing quote last, interior quotes escaped) — anything else is an
error, never a silently mangled value.

**Block values are mini-templates.** A `[set]` value renders exactly like body
text — into the variable instead of the output — so `[if]`, `[for]` and nested
self-closing `[set]`s execute inside block values:

```
[set greeting]Hello [if @vip]dear [/if]@{name}[/set]
[set list][for x in @items]@{x},[/for][/set]
```

Escape a literal tag (`\[if`). Two boundaries: a nested *block* set inside a
block set is a loud error (the outer scan stops at the first `[/set]`), and a
`[break]`/`[continue]` inside a value cannot affect a loop *outside* it — a
value is its own render scope. Errors inside values carry exact template
coordinates.

### Remove a variable — `[unset]`

```
[unset draft]                    the name becomes undefined again
[unset user.email]               remove one key; siblings stay
[unset a, b.c, items.1]          multiple comma-separated paths
```

`[unset path]` removes the value entirely (subtree included) — afterward
`defined` is false, strict mode errors on it, and fallbacks catch it. It is a
no-op when the path doesn't exist (idempotent). Removing an array index leaves
a hole (no reindexing); `[unset g][set g.k = v]` is the idiom for resetting a
container.

### Interpolate

```
@{greeting}, @{name}!
```

`@{path}` is the interpolation. It has a clear close, so it splices anywhere —
even mid-word: `con@{mid}tetur`. A bare `@` in text (e.g. an email address) is
literal; only `@{` starts an interpolation.

**Fallback for falsy values — the `default:` filter.** It triggers when the
value is **falsy** — undefined, empty, numeric zero, or an empty array — the
same rule `[if]` uses. `007` and `"0x"` are truthy and pass through. Its argument is a full
expression, and chaining gives cascading fallbacks:

```
Hello @{user.name | default: "guest"}!
@{price | default: @basePrice}              a variable fallback
@{missing | default: @fb | default: "-"}    cascades through falsy values
```

### Dot-paths (nested to any depth)

A `.` descends one level. A **trailing dot** means "append at the next index".
A numeric segment is an index; a name segment is a key. Intermediate arrays are
created on demand; descending through a scalar is an error.

```
[set fruit. = apple]            append          → fruit[0]
[set fruit. = banana]           append          → fruit[1]
[set user.first = Ada]          keyed
[set order.items. = pen]        nested + append → order.items[0]
[set order.items.0.note = x]    nested keyed leaf

@{fruit.1}  @{user.first}  @{order.items.0}
```

### Comment

```
[# renders to nothing; may span multiple lines #]
```

Standalone directive/comment lines are trimmed (no leftover blank line).
Pass `--no-trim` to keep them.

## Conditionals

```
[if @role == admin]Admin[elseif @role == editor]Editor[else]Guest[/if]
```

Inside a tag, a reference is bare `@name` (the closed `@{name}` form is for
text only and is rejected here); a bareword is an *unquoted string literal*, so
the `@` is what marks "this is a variable".

- comparisons `== != < > <= >=`; both operands numeric → numeric compare
  (`5 == 5.0`, `007 == 7`), otherwise lexicographic
- substring tests `contains`, `starts with`, `ends with` — case-sensitive, on
  the text form; usable in `@()` too and negated with `not`:
  `[if @file ends with ".pdf"]`, `[if @email contains "@"]`
- boolean logic `and` / `or` / `not` with parentheses `( )`
- `defined @path` tests existence
- literals with spaces use `"quotes"`
- falsy = empty string, undefined, numeric zero, and an empty array;
  everything else truthy — a non-empty array included (`[if @items]`)
- the `]` is the body boundary, so `[if @a] yes[/if]` keeps the leading space
  as body

See `examples/conditions.ldt`.

## Loops

```
[for v in @items] … [/for]           one-var: value only
[for k, v in @items] … [/for]        two-var: key/index + value
[for n in 1 to 5] … [/for]           inclusive range (add 'by 2' for a step)
[break]   [continue]                 inside a loop only
```

- iterate any array by dot-path, including nested ones (`@order.items`)
- **nested iteration**: a loop value may itself be a sub-array, so
  `@{v}` renders empty but `@{v.name}` resolves — loop over records
- ranges are `a to b` (inclusive), direction inferred (`3 to 1` counts down),
  optional `by step` (positive). Any bound may be a `@ref`, including the
  **start** (`@lo to @hi by @step`). Ranges stream lazily — a huge range
  costs time, never memory; bounds must fit the platform integer
- loop variables are block-scoped: they don't leak, and any prior binding of
  the same name is restored afterward
- `[break]` / `[continue]` bind to the nearest enclosing loop; output emitted
  before them is kept

**Loop metadata** — inside a `[for]` body, `@{loop.*}` describes the iteration:
`loop.index` (1-based), `loop.index0` (0-based), `loop.first`, `loop.last`
(each `1`/`0`), and `loop.count` (the total). Nested loops each get their own.

```
[for v in @items]@{v}[if not @loop.last], [/if][/for]     → a, b, c
```

See `examples/loops.ldt`.

## Expressions

`@(expr)` evaluates an expression and emits the result — a sibling of `@{path}`
that computes instead of just looking up. It works **anywhere text is emitted**
(body output *and* `[set]` values), and inside it you're in expression context:
references are bare `@name` (the `@{…}` form is rejected there).

```
Subtotal: @(@price * @qty)
[set total = @(@price * @qty + 5)]      capture a computed value
[for n in 1 to 3]@(@n * @n) [/for]       → 1 4 9
```

- **Integer arithmetic:** `+  -  *  /  %` — `/` truncates toward zero; `* / %`
  bind tighter than `+ -`; `( )` groups; unary `-` negates. Operands must be
  integers (a non-integer, or `/ 0`, is an error).
- The **comparison and boolean operators** work too (`==`, `<`, `and`, `not`,
  `defined`, …); a true/false result renders as `1` / `0` — handy for flags:
  `[set adult = @(@age >= 18)]`.
- **`count @array`** gives an array's length (undefined → `0`, a scalar →
  error). It's an expression operator, so it works in `@()` **and** in `[if]`
  conditions:
  ```
  You have @(count @items) item[if count @items != 1]s[/if].
  [if count @cart > 0]…[/if]
  ```
- A literal `@(` is written `\@(`.

The one thing to know: inside `@(...)`, `-` is subtraction, so an unquoted
bareword with a hyphen (`some-value`) must be quoted (`"some-value"`). Plain
`[if]`/`[for]` conditions don't enable arithmetic, so hyphenated barewords keep
working there unchanged. See `examples/expressions.ldt`.

## Filters

A postfix pipe chain transforms a value on its way out. It works in both
`@{ ... }` and `@( ... )`; args follow a `:`, separated by commas, and each arg
is a **full expression**:

```
@{name | trim | upper}
@{items | join: ", "}
@{price | round: @precision}
@{text | truncate: @width - 2, "…"}
@(count @cart * 10 | abs)
```

| Filter | Behavior |
|--------|----------|
| `upper` / `lower` | change case |
| `trim` | strip surrounding whitespace |
| `capitalize` | uppercase the first character |
| `truncate: n [, suffix]` | cut to `n` chars, append optional suffix |
| `join: sep` | array → string (sep defaults to empty) |
| `first` / `last` | first / last element of an array |
| `round [: n]` | round to `n` decimals (default 0) |
| `abs` | absolute value |
| `html` | escape `< > & " '` for HTML |
| `default: value` | fallback when the input is falsy (same rule as `[if]`) |

Notes:
- Filters apply to the **finished value** — compute first, then pipe.
- Arrays flow through the chain (into `join`/`first`/`last`), but the final
  result must be a scalar — an array at the end is an error ("add a join").
- `default` also satisfies `--strict` for an undefined path.
- Filters are **not** available in `[if]`/`[for]` tag conditions — filter into
  a `[set]` first, or use `@( ... )`.
- A `|` in plain text is literal, as always.

See `examples/filters.ldt`.

## Escaping literal delimiters

A `\` before any **non-alphanumeric** character emits it literally, so any
delimiter can be written verbatim:

```
\@{x}     → @{x}       (a literal interpolation, not resolved)
\[set …]  → [set …]    (a literal tag)
\[/set]   \]   \#]     → literal value/comment closers
\\        → \
```

A `\` before a letter, digit, or end-of-line is itself literal, so ordinary
text and Windows paths (`C:\Users`) are untouched. In `[if]`/`[for]`/`@()`
expressions there are no bare escapes — use a `"quoted"` string for special
characters; **inside** such a string the same escape rule applies (`\"` for a
quote, `\\` for a backslash), and strings may span lines. See
`examples/limitations.ldt`.

## Caveats

The full list of edge cases and gotchas — reference forms, escaping, integer
arithmetic, strict mode, trimming, and more — lives in
[`CAVEATS.md`](CAVEATS.md).

## Feeding data in

A template can be driven by an external data context, not just variables it
defines inline. Scalars are stringified (`true`→`1`, `false`→`0`,
`null`→empty), nested arrays are addressed by dot-path, and an inline `[set]`
may override a seeded value.

From PHP:

```php
echo Ldt::render('Hello @{user.first}!', ['user' => ['first' => 'Ada']]);
echo Ldt::renderFile('examples/data.ldt', ['cart' => [['item' => 'Pen', 'qty' => 2]]]);
```

From the CLI:

```
ldt --set user.first=Ada file.ldt        # dotted key → nested path
ldt --json data.json file.ldt            # a whole JSON object as the context
ldt --json data.json --set site=X f.ldt  # later flags win
```

See `examples/data.ldt` with `examples/data.json`.

## Strict mode

`--strict` (CLI) / `strict: true` (PHP) turns an undefined `@{path}` into an
error instead of empty. A `default:` filter satisfies it; any other filter
chain on an undefined path still errors. Refs inside `@()` resolve to empty
rather than erroring (and then fail arithmetic if misused).

## Run it

```
php bin/ldt examples/assignments.ldt      # render a file
php bin/ldt --strict file.ldt            # error on undefined references
php bin/ldt --tokens file.ldt            # dump the token stream
php bin/ldt -                            # render stdin
php tests/run.php                        # run the test suite
```

Or from PHP:

```php
require 'autoload.php';
use Ldtlang\Ldt;

echo Ldt::render('Hello [set who = world]@{who}!');
echo Ldt::renderFile('examples/assignments.ldt');
```

## Editor support

A TextMate bundle for PhpStorm lives in `editor/phpstorm/` — see its README.

## What is *not* possible

Every limitation fails **loudly** with a located error — never by silently
producing wrong output. The complete list:

| Not possible | Why | Instead |
|--------------|-----|---------|
| bare `@name` interpolating in body text | would mangle every email/@-mention | `@{name}` |
| `@{…}` inside expressions | redundant — expressions self-delimit | bare `@name` |
| filters in `[if]`/`[for]` conditions | keeps conditions simple | filter into a `[set]`, or use `@()` |
| float arithmetic (`@(1.5 + 1)`) | integer-only by design (no float-formatting swamp) | keep math integer; `round` formats decimals |
| nested `@( @( ) )` | one expression level | plain `( )` grouping |
| `@()` as a range bound | bounds are literals or refs | `[set hi = @(…)]` first |
| unquoted `\|` `:` `,` or hyphens-in-`@()` inside barewords | they are expression tokens | quote: `"10:30"`, `"super-admin"` |
| a raw `]`/`[/set]`/`#]` inside an unquoted value or comment | delimiter-based scanning | quote the value (`"a ] b"`) or escape: `\]`, `\[/set]`, `\#]` |
| a value starting with a bare `"` that isn't properly quoted | strict quoting — fail loud, never mangle | quote the whole value, or escape: `\"` |
| rendering an array directly | arrays aren't text | `\| join: ", "` |
| nested comments | first `#]` closes | `\#]` for a literal closer |
| bare `\` escapes in expressions (outside strings) | quoting covers it | `"quoted strings"` — with `\"`/`\\` inside |
| case-insensitive substring tests | byte-wise like `==` | `[set e = @{x \| lower}]` then test |

## Deliberately excluded features

Not oversights — decisions, recorded in `TASKS.md`, made to keep the
language small:

| Feature | Why it's out | What covers the need |
|---------|---------------|-----------------------|
| includes / partials | file resolution + recursion complexity | compose in the host app |
| macros / functions | the line where a template becomes a program | loops + sets |
| switch / case | redundant surface | `[if]`/`[elseif]` chains |
| custom / host-registered filters | API-surface growth | the built-in twelve |
| boolean / null literals | two-type model already covers them; `true` would break bareword compares | flags are `1`/empty; undefined is the null |
| regex matching | a second syntax + escaping clash | `contains` / `starts with` / `ends with` |
| ternary `?:` | two mechanisms already exist | inline `[if]…[else]…[/if]` and `default:` |

## Status

Assignment, interpolation (`@{path}` in text, bare `@path` in expressions,
`| default:` fallbacks), inline expressions (`@(expr)` with integer
arithmetic + comparison/logic), filters (`| upper`, `| join: ", "`, …, in both
`@{}` and `@()`), nested dot-paths, comments, conditionals, loops (arrays +
`to`/`by` ranges, `break`/`continue`, nested iteration, `@{loop.*}` metadata),
an external data context (PHP array / CLI `--set` / `--json`), whitespace
trimming, and `\` escaping are implemented.

Planned work and deliberately-excluded features are tracked in
[`TASKS.md`](TASKS.md).
