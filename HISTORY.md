# ldt-lang design history

The condensed record of how the language got to its current shape — decisions,
rejected alternatives, and implementation notes. Moved here from Claude's
project memory on 2026-07-04. Newest entries at the bottom.

ldt-lang lives at `/home/syncroze/Sites/syncroze-ldt-lang`.

## Interpolation / range / escape redesign (from `[[path]]` + `..`)

- Reference syntax is CONTEXT-EXCLUSIVE:
  - Body text + `[set]` values: `@{path}` only. A bare `@` in text is literal
    (emails survive); only `@{` triggers. (Bare `@path` in text was considered
    and rejected — it would mangle every email.)
  - Tag expressions (`[if]`, `[for … in]`): bare `@path` only. The closed
    `@{path}` form is REJECTED there with a pointed error — tag operators /
    brackets / keywords already delimit, so `@{}` was redundant. In conditions
    the `@` distinguishes a variable from an unquoted string literal.
- Ranges are `a to b` with optional `by step` (keywords), replacing `..` and
  `:`. This also fixed the old "range start can't be a ref" limitation —
  `@lo to @hi by @st` works, because `to` is read via lookahead after the
  first bound.
- Escaping: `\` before any NON-alphanumeric char emits it literally (`\@{`,
  `\[set`, `\/]`, `\#]`, `\\`); before a letter/digit/EOL the `\` is literal
  (Windows paths survive). No `\` escape inside expressions — use `"quoted"`
  strings there. In `[set]` values, escapes are cooked by re-lexing; the
  value/comment scanners use `Lexer::readEscapedUntil` so an escaped closer
  doesn't terminate early.

## Inline expressions `@(expr)`

A sibling of `@{path}` that EVALUATES and emits; works anywhere text is
emitted (body + `[set]` values). Inside `@()` references are bare `@name`
(`@{}` rejected). Integer arithmetic `+ - * / %` (`/` truncates, `* / %` bind
tighter than `+ -`, unary `-`, `( )` grouping); non-integer operand or `/0` →
source-located error. Comparison/logic also available (true/false render as
`1`/empty → flags). `\@(` escapes a literal opener. Implementation: ExprParser
gained `$closer` (`]` for tags, `)` for `@()`) + `$arithmetic` flag (ON only in
`@()`, so hyphenated barewords still work in `[if]`/`[for]`); Expr ARITH/NEG
kinds; EXPR token through Lexer/Parser/Interpreter/Trimmer. Note: inside `@()`,
`-` is subtraction, so hyphenated string literals need quotes.

## Roadmap batch: feed data, loop metadata, defaults

- FEED DATA: `Ldt::render($source, array $data = [], $strict, $file, $trim)` +
  `renderFile($path, $data, …)` seed via `Environment::seed()` (scalars
  stringified: bool true→"1", false/null→""; nested arrays kept). CLI
  `--set key=value` (dotted key → nested path) and `--json file`
  (array_replace_recursive); later flags win; inline `[set]` overrides seeded.
  NOTE: `$data` is the 2nd positional param of `render` (was `$strict`).
- LOOP METADATA: `@{loop.index}` (1-based), `.index0`, `.first`, `.last`
  (1/empty), `.count`. Bound in `Interpreter::walkFor` as a nested `loop`
  array, block-scoped (snapshot/restore); nested loops each own theirs; a user
  `loop` var is shadowed and restored.
- DEFAULT: `@{path or fallback}` — optional ` or ` split in `readInterp`
  (first split; fallback may contain "or"), unquoted; the default also
  suppresses `--strict`. Literal fallback only.

## Array length `count @path`

Expression operator (prefix keyword like `defined`), works in BOTH
`[if]`/`[elseif]` conditions and `@()`. Undefined → 0, scalar → error (via
`Environment::sizeOf()` returning `?int`; null = scalar). `Interpreter::
evalGuarded()` wraps expression evaluation so runtime errors get coordinates.
`count` IS a reserved word (quote "count" for the literal). A range-bound form
(`1 to count @x`) was intentionally not added — `@{loop.count}` covers it.

## Filters / pipes

Postfix chain `value | filter: arg, arg | ...` in BOTH `@{}` and `@()` (NOT in
tag conditions — pointed error). Colon args, comma-separated, each arg a FULL
expression (arithmetic on in args). v1 set in `src/Filters.php`: upper lower
trim capitalize truncate(n[,suffix]) join(sep, ''-default) first last
round([n]) abs html default(fallback). Implementation: ExprParser tokenizes
`|`/`:`/`,` (boundary chars now — unquoted barewords can't contain them;
`10:30` needs quotes) + closer `}`; `parseWithFilters()` (used by `@()`, wraps
into Expr FILTERED kind) + `parseFilterChain()` (used by `@{}`);
`Lexer::readInterp` does a quote-aware head scan (head = path/or-default up to
the first top-level `|`). Arrays flow through the chain (a REF inner uses
`lookupRaw`, so `@{items|join}` and `@(@items|join)` both work); the final
result must be a scalar ("add a join" error). The `default` filter and the
`or` fallback both satisfy `--strict`. An unquoted or-fallback can't contain
`|`.

## Substring operators

`contains`, `starts with`, `ends with` — comparison-level operators in
`[if]`/`[elseif]` AND `@()`. Case-sensitive/byte-wise on the text form
(str_contains / str_starts_with / str_ends_with in `Expr::evalCompare`);
empty needle always true (PHP semantics); negate with `not`.
CONTEXT-SENSITIVE (`ExprParser::peekWordOp` in parseComparison — recognized
only in operator position), so NOT reserved: `[if @x == contains]` still
compares the literal. `with` is REQUIRED after starts/ends. Chosen over
`startsWith` (camelCase outlier), `startswith`, and bare `starts`.
Boolean/null literals, regex and ternary moved to NOT PLANNED (the two-type
model covers bools/null; substring ops replace regex; `[if]`/`[else]` +
`or`/`default` replace ternary).

## Optimization pass (behavior-preserving)

- `Lexer::run()` bulk-scans literal text with `strcspn('\@[')` + `pushText`
  (was ~14 calls/byte): 500KB plain text 278ms → 2.4ms (~118×).
- `advanceBy` is bulk (substr_count newlines, col from strrpos) — error
  coordinates verified exact on multi-line input.
- `strspn`/`ctype` replaced per-char preg in readPathText / boundaryAt /
  ForHeader; `readEscapedUntil` strcspn-scans.
- Interpreter memoizes `[set]`-value parses (`$valueCache` keyed by the value
  string, bounded by distinct set-values; accumulator loop 11.2 → 2.0ms).
- `Environment::segmentsOf()` = the single shared dot-path validator
  (Lexer/ExprParser/ForHeader delegate); `lookup`/`defined` delegate to
  `lookupRaw`; dead `Lexer::readUntil` removed.

## Quoted [set] values — the strict rule (user-designed)

Previously set values were raw text (quotes literal, edges always trimmed), so
edge spaces were unstorable and `[set sep = " / "/]` kept its quote marks — a
recurring trap. The user proposed, and we adopted, a STRICT rule (rejecting the
naive first/last strip, which silently mangles `"yes" or "no"` → `yes" or "no`):

- value not starting with `"` → raw text as before (interior quotes literal);
- value starting with an unescaped `"` → must be a properly quoted WHOLE value
  (closing `"` last, interior quotes escaped `\"`) → outer quotes stripped,
  inside verbatim (edge spaces now storable); anything else → loud error;
- `\"...` → literal quotes via the normal escape system (already worked).

Applies to both set forms AND quoted `or`-fallbacks (`Lexer::strictUnquote`,
shared; a quoted fallback also cooks its `\X` escapes via `Lexer::unescape`
since no later render pass touches defaults). The old naive `unquote()` was
removed. Consequence: a lone `"` value now needs `\"`.

## The unified falsy rule (user-designed)

Booleans used to textualize as `1`/`''` (inherited from PHP's string cast) and
`or` (undefined-only) differed from `default:` (undefined-or-blank). The user
unified everything onto ONE rule:

- **Booleans textualize as `1` / `0`** everywhere (`@()` output, stored flags,
  comparison operands, filter args, `loop.first`/`loop.last`, seeded `false`).
  Seeded `null` stays `''` (absence of an answer vs a "no" answer). This also
  made `@((…) == 0)` compare correctly (before, `'' == 0` failed strcmp).
- **`or` and `default:` trigger on FALSY** — the exact `[if]` rule (undefined,
  empty string, numeric zero) plus the empty array. So `[if]`, `or` and
  `default` always agree. `007` (= 7) and `"0x"` are truthy and pass through.
- Rejected intermediate: "fallback = missing-or-blank only" (0 passing) — the
  user explicitly chose 0 → fallback (Twig-style `default` semantics).
- Consequence (accepted): a genuine `0` cannot survive an attached fallback;
  to display a zero, don't attach one. `@(cond | default: "no")` catches a
  false result again (its `'0'` is falsy).

Implementation: `Expr::toStr` + `Filters::str` (`'0'`), `Filters::defaultTo`
(truthy-based), `Interpreter::resolve` (`fallsBack()` helper on both paths),
`walkFor` metadata, `Environment::normalizeValue`.

## `[unset a, b.c]` — in-template removal

Grew out of the "what is null?" discussion: undefined is ldt's null, but
nothing in-template could *produce* undefined. `[unset]` fills that hole.

- Syntax: `[unset path]` with comma-separated multi-path (`[unset a, b.c]`) —
  plain `]` closer like other argument tags (`/]` is reserved for closing a
  *value*). Word-boundary keeps `[unsettle]` literal.
- Semantics (user-confirmed): removal makes the path **UNDEFINED, not empty**
  — `defined` → 0, strict errors, fallbacks catch. Removing an empty-array
  instead was explicitly considered and rejected (`defined` would then lie
  about something explicitly removed).
- Idempotent: missing / through-scalar paths are silent no-ops (PHP unset()
  spirit). Trailing dot errors. Index removal leaves holes, no reindexing;
  next append continues past the old max. Standalone lines trim.
- Reset-a-container idiom: `[unset g][set g.k = v/]` (set auto-vivifies
  fresh); `[set g = /]` can't do it (makes a scalar).
- Implementation: Token::UNSET (`['paths' => segments[]]`),
  `Lexer::readUnset`, Parser leaf, `Environment::remove()` (named remove, not
  unset, to dodge the PHP keyword), Trimmer TRIMMABLE.

## Uniform `]` closer for `[set = ]` (user-designed hard break)

The `=` form used to close with `/]` — the only tag that didn't close with a
plain `]`. The user flagged the inconsistency vs `[unset a, b]` / `[if]` /
`[for]`; we cut over:

- `[set path = value]` — value runs to the first unescaped `]`; a literal `]`
  in an unquoted value is `\]`. The `\/]` escape is retired; `/]` now has zero
  meaning anywhere in the grammar ("tags close with ]; block set closes with
  [/set]; comments with #]"). Bare trailing slashes work: `http://x.com/`.
- **Quotes protect `]`** (the user's refinement): a value starting with `"`
  switches the scanner to quote-aware mode — scan to the closing `"`, then
  require `]`. `[set b = "sdf ] vb"]` needs no escapes; content between the
  closing quote and `]` is a loud error (the strict-quote rule, now enforced
  by the scanner itself). `strictUnquote` remains only for the block form and
  or-fallbacks; new `Lexer::readQuotedValue` handles the `=` form.
- HARD BREAK, no compat mode (rejected: accepting both closers would make
  genuine trailing slashes unwritable). Old quoted `"x"/]` fails loudly;
  old unquoted `x/]` silently stores `x/` — hence the full in-repo migration
  (~150 test sites, all examples, all docs). Historical `/]` in THIS file's
  earlier entries is left as written (it describes past states).

## Quoted-string consistency audit (user-requested)

A whole-codebase audit of quoted-string handling found the contexts had
drifted; three fixes landed (finding 4 was documented, not changed):

1. **Expression strings support escapes** — `ExprParser::readQuoted` now
   applies the universal rule INSIDE `"..."`: `\` before non-alnum yields the
   char (cooked at parse time — `\"`, `\\`, `\|`), before a letter/digit it
   stays literal (`"C:\Users"` unchanged). Before this, `[if @a == "say
   \"hi\""]` was unparseable and CAVEATS' own advice ("use a quoted string")
   broke down when the special char was a quote.
2. **Expression strings may span newlines** (the `\n` stop removed) —
   uniform with every other quoted value.
3. **Block-form `[set]` got the same mode split as the `=` form** — a body
   whose first non-ws char is `"` reads quote-aware, so quotes protect a
   literal `[/set]` (`[set x]"a [/set] b"[/set]` works). New
   `Lexer::skipAllWs`; raw bodies no longer pass through `strictUnquote`
   (structurally can't start with a bare quote), which now serves only
   or-fallbacks.
4. **Comments** (documented only): quotes never protect `#]` — comments are
   raw prose; `\#]` is the escape.

## Second consistency batch (user-directed): mini-templates, `or` removed, arity, context keywords, --set validation

From a second whole-codebase audit (findings A–G):

- **A — Block values are mini-templates.** Previously a value re-lexed only if
  it contained `@{`/`@(`/`\` — so `[if]`/`[for]` inside a value executed or
  stayed literal depending on an unrelated character (silent!). Now a `[set]`
  value renders exactly like body text (fast path: no `\@[` chars → as-is;
  parse cache). `[if]`/`[for]`/self-closing `[set]`/`[unset]` execute in block
  values; `\[if` for literal tags; tag openers in the `=` form and nested
  BLOCK sets fail loudly. `interpolateValue` → `renderValue`.
- **H — the `or` fallback was REMOVED** (user's call: "default is powerful").
  `@{x or "f"}` → loud `invalid path` error. `default:` covers everything —
  full-expression args (`default: @fb`, `default: @n * 10`) and cascading
  chains — discovered during finding G (or-with-variable). This superseded
  findings B/C/G entirely and deleted `strictUnquote`/`unescape`/`fallsBack` +
  the interp head's quote-opaque scan. `or` remains the logical operator.
- **D — filter arity enforced** (ARITY map: most take none; round/join ≤1;
  truncate 1–2; default exactly 1). Extra args used to be silently ignored.
- **E — `count`/`defined` are context-sensitive** (operator only before a
  `@ref`, else bareword literal) — no reserved words remain beyond structure
  words. The "expected a @reference after 'count'" error no longer exists.
- **F — `--set` keys validated** via `Environment::segmentsOf` (CLI error);
  seeded PHP-array keys documented as host responsibility. CLI arg loop is
  now wrapped in try/catch so these report as `error:` instead of fatals.

## Third audit batch: exact value coordinates, seed guard, header newlines

- **P1 — exact error coordinates inside [set] values.** Value re-lexing used
  to restart line/col at 1:1, so every error inside a value pointed nowhere —
  intolerable once block values became mini-templates. Now: the Lexer takes
  base line/col; the SET token records where its value text begins ('vline'/
  'vcol', captured per branch — after the opening quote for quoted forms,
  at the first content char otherwise); the Interpreter's value cache is
  site-keyed (line:col|value). Errors inside values are indistinguishable
  from body-text errors. (The cheap rethrow-at-the-[set]-tag wrapper was
  considered and rejected — multiline mini-template values need real inner
  positions.)
- **P2 — [break]/[continue] cannot cross the value boundary** (kept by
  design, now documented in CAVEATS 4d; its error now points at the actual
  [break] thanks to P1).
- **P3 — seeding guard**: non-scalar/array/null seeded values throw
  InvalidArgumentException naming the key path (was a raw PHP Error from a
  string cast).
- **P4 — newlines are whitespace in every tag header** ([for]/[set]/[unset]
  headers and pre-closer gaps; conditions already allowed them). Value
  regions unaffected.

## Fourth audit batch: array truthiness, CRLF, marker whitespace, coordinates

- **Non-empty arrays are truthy.** `[if @items]` used to be false even for a
  populated array (a bare ref reads arrays as ''), while `default:` — which
  receives the raw value — treated it as truthy: the documented "one falsy
  rule" was silently violated. Now truthiness of a bare reference consults
  the raw value (`Expr::truthyIn`, used by `[if]`/`not`/`and`/`or`):
  non-empty array → truthy, empty array → falsy. Comparisons and text output
  are unchanged (an array still reads as ''). Alternative considered and
  rejected: erroring on `[if @array]` — CAVEATS 9b already promised
  empty-array falsiness, implying arrays are testable.
- **`\r` is expression whitespace.** ExprParser was the only scanner that
  skipped just space/tab/`\n` — in a CRLF file a multi-line condition glued
  the `\r` into a bareword (`"yes\r" != "yes"`: silently false). `\r` is now
  both whitespace and a bareword boundary, matching every other skipper.
- **Marker tags accept whitespace before `]`.** `[else ]`/`[break ]`/
  `[continue ]` were silently literal (both branches rendered / loops never
  broke); `[/if ]`/`[/for ]` failed confusingly. Markers now follow the same
  header-whitespace rule as every other tag; `[else x]` stays literal. The
  value/comment closers `[/set]` and `#]` deliberately remain exact sentinels.
- **Exact coordinates, remaining gaps closed.** Parse errors on continuation
  lines of multi-line headers (legal since the third batch) counted their
  position against the first line; ExprParser/ForHeader `fail()` now walk
  the newlines up to the offset (correct even for the negative-lineStart
  case of re-lexed [set] values). Runtime errors in `[elseif]` conditions
  pointed at the `[if]`; IfNode branches now carry their own tag coordinates.
- **`unexpected 'Array'`** — leftover-reference errors interpolated the ref's
  segment array (with a leaking PHP warning); messages now print `@path`.
- **CLI `--json` list merge**: `array_replace_recursive` left tail elements
  when a shorter list overrode a longer one (`["x","b","c"]`); lists now
  replace wholesale, maps still deep-merge key by key.
- **Documented, not changed**: `round`/`abs` float precision (scientific
  notation beyond ~15 digits); `@a.-1` inside `@()` reads `-` as subtraction
  (fixing it would break `@a-1`); both now in CAVEATS.

## Fifth audit batch: CLI trust, +N Numbers, filter-arg semantics

- **CLI argument validation.** Unrecognized arguments fell into the "it's the
  input file" branch, so a typo'd flag (`--stric`) was silently swallowed —
  and then *overwritten* by the real filename, rendering with strict off and
  exit 0. Unknown `-`-prefixed options and extra file arguments are now
  errors. A leading UTF-8 BOM (an editor artifact) is stripped by the CLI
  and by `Ldt::renderFile`; embedded `render()` callers own their bytes.
- **`+5` is a Number.** The text-form rule accepted `-5` but not `+5`, so
  `[if @x == +5]` string-compared (silently false for x=5) while `@(+5)`
  worked via unary plus. The optional sign is now `[+-]?` at every
  classification site (truthy, compare, arithmetic operands, range bounds
  incl. `[for n in +1 …]` literals, filter number guards). Accepted ripple:
  `+0` is now falsy, like `-0`.
- **Filter arguments read plain refs raw, and `default:` is lazy.** Args
  evaluated as scalar expressions, so `default: @items` read '' — an array
  could never be a fallback (`| join` then failed bafflingly). A plain-`@ref`
  argument now uses the same raw rule as the chain input (`Expr::evaluateRaw`,
  shared); scalar-expecting args (`truncate`/`round`/`join`) reject arrays
  loudly. `default:` evaluates its argument only when the fallback fires, so
  an error in an unused fallback never surfaces. Arity is checked before
  evaluation for every filter (`Filters::checkArity`).
- **Seed guard extended**: non-finite floats (INF/NAN) throw like objects —
  they stringify to words, not Numbers. Big-but-finite floats stay accepted
  and documented (PHP stringifies past ~15 digits scientifically).
- **Pointed placement messages**: duplicate `[else]` → "duplicate [else] in
  [if]"; `[elseif]` after `[else]` → "[elseif] cannot follow [else]" (both
  used to claim "without matching opener").
- **Documented, not changed**: `--strict` guards only `@{}` interpolation
  (CAVEATS §8 rewritten — `[if @missing]`/`[for v in @missing]` are the
  existence tests and stay silent by design; changing this would break the
  idiom); CLI later-wins wholesale seeding vs the template's path-conflict
  error (different layers: seeding describes an end state).

## Sixth audit batch: escape-at-EOL, reserved loop var, UTF-8 truncate

- **`\` before end-of-line is literal.** CAVEATS §2 always said so ("prose
  backslashes survive"), but the code treated a newline like any other
  non-alphanumeric and consumed the backslash — a doc/code contradiction and
  silent character loss. `\r`/`\n` now join letters/digits/EOF in the
  "backslash stays" class, in body text (Lexer::readEscape) and inside
  `"..."` expression strings (ExprParser::readQuoted) alike.
- **`loop` is rejected as a `[for]` variable name.** The metadata binding is
  applied after the loop variables each iteration, so `[for loop in @items]`
  rendered the metadata (empty) instead of the values, silently. Now a loud
  header error with exact coordinates ("'loop' is reserved for loop
  metadata"), in the same spirit as the key≠value guard. A pre-existing
  user variable named `loop` is still just shadowed and restored.
- **`truncate` backs off to a UTF-8 boundary.** Byte-count semantics kept,
  but a cut inside a multi-byte sequence dropped a dangling lead byte into
  the output (mojibake), with the suffix after it. The incomplete tail is
  now stripped via pure byte arithmetic (continuation-byte scan + expected
  length from the lead byte) — complete characters at the edge are kept,
  no mbstring dependency. Trade-off accepted: assumes UTF-8 text; a raw
  Latin-1 high byte at the cut is also dropped.
- **Documented**: `upper`/`lower`/`capitalize` are ASCII-only (byte policy,
  like the substring operators) — mbstring-based case folding was rejected
  to keep the zero-dependency promise.
- Checked clean this round: autoloader, Trimmer line detection (multi-line
  comments, CRLF, trailing padding), quote protection of closers in filter
  chains, `count` through-scalar consistency, comment closers.

## Seventh audit batch: lazy ranges, overflow guards, block-mismatch messages

The seventh round targeted the three never-probed areas from the audit
surface map in `TASKS.md`: the Trimmer, block-boundary parsing, and range
edges. Ranges yielded the round's only real bugs.

**Range overflow was an infinite loop (fixed).** With a range ending at
`PHP_INT_MAX`, the increment `$n += $step` overflowed to float — and the
float compared `<=` the (float-rounded) end forever, so the loop appended
pairs until the process was OOM-killed. Worse, PHP's `(int)` cast silently
*saturates*, so a literal like `9223372036854775810` quietly became
`PHP_INT_MAX` and hit the same loop. Fix in three parts: a shared
`Expr::intInRange()` check (a digits string must round-trip through `(int)`),
applied to literal bounds in `ForHeader::readBound` and ref bounds in
`Interpreter::bound` (loud "out of the integer range" errors); and an
explicit stop in the iteration before the increment could overflow, in both
directions (`PHP_INT_MIN` had the mirror bug). Alternative considered:
clamping out-of-range literals to the limit — rejected, silent mangling.

**Ranges now stream lazily (fixed).** `rangePairs` used to materialize every
`[index, value]` pair before the first iteration, so `[for n in 1 to
50000000][/for]` was an uncatchable memory-exhaustion fatal even with an
empty body. It is now a generator; `loop.count` is computed arithmetically
(`intdiv(span, step) + 1`, saturating to `PHP_INT_MAX` on the astronomically
long `INT_MIN..INT_MAX` span). Arrays stay materialized — they're bounded by
data that already exists. No observable behavior change for any terminating
template; the accumulator-2k benchmark is unchanged (~2.5ms). Alternative
considered: an iteration cap — rejected; templates are trusted input and a
long loop is now time-bounded, which Ctrl-C handles.

**Wrong-kind closers name the block they interrupt (fixed).** `[if 1]a[/for]`
said `unexpected [/for] without matching opener` — misleading, since the real
mistake is the unterminated `[if]`. The parser now keeps a stack of open
blocks; a foreign closer inside one reports `unexpected [/for] inside [if]
opened at 1:1 (missing [/if]?)`. The plain message survives for true
top-level strays.

**Trimmer coordinate drift (fixed).** The trimmer dropped line/col on the
newline atoms it re-emitted and gave all split TEXT parts the token's start
position, so post-trim TEXT tokens could report `1:1`. Atoms now track exact
per-part coordinates and the terminating newline atom is passed through
intact. Debug-only (`--tokens`) — no error path reads TEXT coordinates.

**Documented, not fixed:**
- *No nesting/iteration caps* (CAVEATS §13): ~100k nested `[if]`s hit PHP's
  stack/memory limits as a fatal, not a `SyntaxError`. Templates are trusted
  input; 20k deep verified fine. A depth cap was considered and rejected as
  an arbitrary knob solving a non-problem.
- *Escaped whitespace doesn't protect a standalone line* (§11): `\ ` is
  already plain text when the trimmer decides. A fix needs escape provenance
  threaded through the token stream — real complexity for a corner nobody
  has hit; the workaround (visible text or `@{}`) is trivial.
- *Only `\n` ends a line* for the trimmer (§11): CRLF works; CR-only files
  are one long line.
- *Range keys are 0-based positions*; *an undefined range bound errors* while
  an undefined array iterates zero times (§10) — both deliberate, now stated.

Everything else probed came back clean: CRLF standalone lines, multi-line
headers/comments/block-sets as standalone lines, trimming parity inside
`[set]` mini-templates, unterminated-block coordinates, `[break]`/`[continue]`
boundaries, `by 0`/negative/float/array bounds. 383 tests.

## Eighth audit batch: environment paths, comments, --strict — first clean round

The eighth round took the remaining code candidates from the audit surface
map: environment path semantics, comments, and `--strict` interactions. For
the first time, **no code changed** — every probe came back consistent, with
pointed errors and exact coordinates. Verified clean: append-onto-scalar
conflicts (message carries the path), `[unset]` of a loop variable or of
`loop` itself mid-loop (rebinds next iteration, restores after), unset-last-
key leaving a defined-but-falsy empty array, unterminated-comment coordinates
(multi-line, and re-seated inside `[set]` block values), line tracking across
multi-line comments, `\#]`, comment-in-header rejection, strict errors at
exact coordinates inside `[set]` values, strict + chains without `default:`,
strict + `loop.*` outside a loop.

Six behaviors were deliberate but unstated; all are now in CAVEATS:

- **Numeric segments are integer indexes** (§12): `.01`/`.1`/appended `1`
  collide — PHP's own array keying. Consequence: a seeded *string* key
  `'01'` is unreachable from templates. Alternative considered: keeping
  numeric segments as written (string keys) — rejected, it would break the
  `.1`-equals-appended-slot-1 rule the whole dot-path model rests on.
- **Scalar-over-map replaces wholesale** (§4c): `[set a = x]` after
  `[set a.b = 1]` drops the subtree silently; only descending *into* a
  scalar is loud. Making replacement loud was rejected — `[set]` is an
  override by design, and requiring `[unset]` first would break the common
  "reassign a name" case.
- **Seeded `null` is an empty scalar** (§12): consistent with the null → `''`
  rule; an absent key is the way to say "let the template build this".
- **Negative-key append starts at 0** (§4c): PHP's max-int-key-plus-one.
- **`[#]` is unterminated; `[##]` is the minimal comment** (§4): the `#`
  is not shared between opener and closer.
- **Filter args are never strict-guarded — `default:` included** (§8):
  `@{missing | default: @alsoMissing}` is `''` under `--strict`, no error.
  Guarding fallback args was rejected: a fallback exists to absorb
  missing data, and expressions are uniformly unguarded (§8's one rule).

No new tests (no behavior changed); 383 stands. With this round every code
area of the audit map is ✅ — only the documentation-hygiene pass (editor
grammar, docs/examples cross-check) remains.

## Ninth audit batch: editor grammar rebuilt, docs hygiene — the map closes

The final map items were tooling and documentation, not engine code.

**The TextMate grammar had drifted badly.** Written before filters, the
substring operators, marker whitespace and the escape rule, it highlighted
`\[if` as a live tag (backwards — the escape makes it literal), matched
markers only in their exact no-space form, colored `==`/`<`/`>` in plain
prose (the comparison rule was top-level), and knew nothing about `| filter:`
chains or word operators. Rebuilt: escapes match first; `[if]`/`[elseif]`/
`[for]`/`[unset]` headers are begin/end regions with refs, quoted strings,
signed numbers (`+5`) and the full word-operator set colored; filters get
their own scope in `@{}`/`@()` (not in headers — the engine rejects them
there); symbol operators are scoped to headers/expressions only. `[/set]`
and `#]` stay exact-match — they are exact sentinels in the engine too.
Validated with `plistlib` plus a regex-compile pass.

**Docs/examples cross-read.** Three stale `or`-fallback references had
survived in `docs/ldt-lang.html` since the batch-#13 removal (quoted-values
note, `default:` filter row, and the strict-mode paragraph claiming "an `or`
fallback … satisfies it"). `[unset]` turned out to be the only construct
with zero example coverage — `examples/assignments.ldt` now demonstrates
removal, index holes + append-past-hole, multi-path no-op unset, and the
reset idiom, with rendered output verified. CAVEATS sections were physically
reordered into 10 → 11 → 12 → 13 (they read 10, 12, 11, 13 after
incremental appends). README, CLAUDE.md, TASKS and the test counts (383)
all agreed already.

With this round every candidate on the audit surface map is ✅. The engine
was untouched; 383 tests stand.

## The `[= expr]` redesign: one emit form, `@` = read-only (2026-07-05)

The pre-launch syntax consolidation. Starting question: "should `[set]` take
`@` for consistency (`[set @name = x]`)?" The codebase analysis showed the
existing rule was already coherent — **`@` marks a READ; a write target is a
bare name** (the grammar slot admits nothing else, so a sigil there carries
zero information) — and the decision was to keep that rule and consolidate
the emit side instead.

**Decided:**
- `@{path}` (interpolation) and `@(expr)` (inline expression) are REPLACED by
  a single emit tag: **`[= expr | filters]`** — an ERB-`<%=`-style member of
  the bracket-tag family. `[= @name]` is the everyday interpolation;
  arithmetic/comparison/logic/count/defined/filters all work inside.
- `@` is no longer special in TEXT at all — the lexer's text scanner triggers
  only on `[` and `\`. Emails, handles, `(@mention)` are safe by construction
  (no escape needed, ever).
- Writes stay bare: `[set name]`, `[unset a, b]`, `[for k, v in @items]`.
- The self-closing `[set … = value]` scan became BRACKET-AWARE (counts
  unescaped `[ ]` pairs), so `[set total = [= @price * @qty]]` and
  `[set msg = Hi [= @name]!]` nest directly. Consequence: an UNPAIRED literal
  `[` or `]` in an inline value now needs `\[` / `\]` (or quotes).
- CLEAN BREAK, no legacy: `@{`/`@(` get no pointed "that was the old syntax"
  errors (the language never launched). `@{x}` in text is just literal text;
  `@{` in an expression fails as a plain invalid reference.

**Rejected on the way:**
- `[set @name = x]` / `[unset @name]` — sigil-on-write. Rejected in favor of
  the read/write rule (PHP-style sigil-everywhere was the alternative).
- `(@path | filters)` as the emit form — `(@handle)` appears in real prose
  (silently eaten in lax mode), and `(@x)` vs `@(x)` mirror-typo confusion.
- `@(@name)` (unify on `@()`) — double-@ stutter on the most common operation.
- `${}`/`#{}`/`%()`/`=(`/`:(` sigils — shell/JS/Python collisions, comment-char
  overload, emoticons.

**Implementation:** one EMIT token replaced INTERP+EXPR (Lexer readEmit →
ExprParser::parseWithFilters, closer `]`, arithmetic ON). Interpreter::emit()
preserves both old semantics: a plain/filtered REF keeps direct-lookup
behavior (strict guard, raw-into-filters, arrays render ''), any other
expression evaluates and renders (bools → 1/0 textualization unchanged).
ExprParser lost the `$closer` param, `parseFilterChain`, and the `@{` pointed
error; gained a loud guard against zero-length barewords (a stray `{`/`}` in
an expression previously spun forever — pre-existing bug). Trimmer treats
EMIT as output (never trims its line). All 390 tests green (the 383 rewritten
+ new coverage: bracket-aware nesting, unpaired-`[` error, literal
`@{`/`@(`/`(@handle)` in text, `[= "a]b"]` quoting); every example rewritten
and verified; docs/index.html, the Prism grammar + ldt theme, and the
CLAUDE.md cheat sheet all updated in the same pass.

## Post-redesign audit batch (2026-07-06)

The verify-everything pass after the `[= expr]` redesign. Engine untouched —
every claim checked against execution, gaps locked with tests (390 → 398):

- **Bracket-aware value scanner**: mid-value quotes protect nothing (only a
  whole-value quote does) — `[set a = say "[" here]` is a loud unterminated
  error; locked with a test and spelled out in caveat §4. A mid-value `]`
  still ends the value silently (the documented "first top-level ]" rule,
  unchanged from before the redesign).
- **`[= (@ref)]` is strict-guarded**: parens parse away, so a parenthesized
  plain ref keeps plain-ref semantics (guard included). Deliberate now,
  locked with tests either way (guarded when alone, unguarded inside a
  computed expression: `[= @missing == ""]` renders `1` under --strict).
- **Stray `{`/`}` guard** (the ex-infinite-loop): message locked by test.
- **Negative-index trade-off** verified both ways: `[if @a.-1]` works,
  `[= @a.-1]` errors (arithmetic context) — both locked.
- **Nested `[= [= ] ]` errors** — locked.
- **Docs cross-read**: every executable claim in docs/index.html verified
  against the engine; caveat §8's strict example corrected to show the real
  behavior (computed-expression refs are unguarded and render, arithmetic
  on '' fails in ANY mode, not just strict).
- **FILES.md** gained the missing `docs/colors.html` and
  `docs/assets/ldt-theme.css` entries; **bin/ldt** read end-to-end (clean,
  EMIT token dump included).

## Where things stand

`TASKS.md` tracks the roadmap: DONE = feed-data, loop-metadata, default,
count, filters, substring-ops, optimization-pass, quoted-set-values, unified-falsy, unset, uniform-set-closer, mini-templates+or-removal+arity+context-keywords,
and the `[= expr]` emit redesign. NOT
PLANNED = includes, macros, switch/case, custom filters, bool/null literals,
regex, ternary. DEFERRED = nothing (the audit surface map in TASKS.md is
fully ticked). 398 tests in `tests/run.php`; examples cover every feature;
the GitHub Pages site (`docs/index.html`, live at
https://syncroze.github.io/ldt-lang/docs/) is the single reference doc —
`README.md` is now just an intro + link, and `CAVEATS.md` was retired after
its edge-case sections (quoted values = §4b, etc.) were merged into the
Pages site's "Caveats" section (the standalone `docs/ldt-lang.html`/`.md`
page from an earlier round was retired the same way, into README, before
README itself was trimmed down).
