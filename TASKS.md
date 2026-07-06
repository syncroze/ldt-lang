# ldt-lang tasks

Roadmap and decisions for ldt-lang. The core language (assignment,
interpolation, `@(expr)`, conditionals, loops, comments, escaping, trimming) is
complete; this tracks what's planned on top of it.

Last updated: 2026-07-05.

---

## Done (2026-07-04)

### 1. Feed data in ✅
- PHP: `Ldt::render($source, array $data = [], …)` / `renderFile($path, $data, …)`
  seed the environment (scalars stringified, nested arrays kept).
- CLI: `--set key=value` (dotted keys → nested path) and `--json data.json`
  (deep-merged); later flags override earlier ones; inline `[set]` overrides
  seeded values.
- See `examples/data.ldt` + `examples/data.json`.

### 2. Loop metadata ✅
- `@{loop.index}` (1-based), `@{loop.index0}`, `@{loop.first}`, `@{loop.last}`,
  `@{loop.count}`. Block-scoped per loop; nested loops each get their own; a
  user `loop` variable is shadowed and restored.

### 3. Default for undefined ✅ (superseded by #13)
- Originally shipped as the `@{path or fallback}` syntax; later removed in
  favour of the strictly more powerful `| default:` filter (expression args,
  variables, cascading). See #13.

### 4. Array length ✅
- `count @array` — an expression operator (prefix keyword like `defined`), so it
  works in `@()` **and** in `[if]`/`[elseif]` conditions. Undefined → `0`, scalar
  → error. `count` is now a reserved word. Range-bound form (`1 to count @x`) not
  added — `@{loop.count}` + direct iteration covers the in-loop case.

### 5. Filters / pipes ✅
- Postfix chain `value | filter: arg, arg | ...` in both `@{}` and `@()`; colon
  args, comma-separated, each arg a full expression. Not available in tag
  conditions (clear error).
- v1 set: `upper lower trim capitalize truncate join first last round abs html
  default`. `or` fallback kept alongside the `default` filter; both satisfy
  `--strict`. Arrays flow through the chain but the final result must be a
  scalar.

### 23. The `[= expr]` emit redesign — `@` reads, writes bare, one emit form ✅
- `@{path}` and `@(expr)` replaced by the single bracket-family emit tag
  `[= expr | filters]`; `[= @name]` is the everyday interpolation. `@` is no
  longer special in text (emails/handles safe by construction; the text
  scanner triggers only on `[` and `\`). Writes stay bare (`[set name]`,
  `[unset a, b]`, `[for k, v]`) — the rule is "reads carry `@`, writes don't".
- Self-closing `[set … = value]` scan is bracket-aware: `[set total =
  [= @price * @qty]]` nests; an unpaired literal `[`/`]` needs `\[`/`\]`.
- Clean break, no legacy errors (pre-launch). One EMIT token replaced
  INTERP+EXPR; plain-ref emits keep the old `@{}` semantics (strict guard,
  raw-into-filters, arrays render ''), computed emits the old `@()` semantics.
- Full sweep in the same pass: tests rewritten (390 green), all examples,
  docs/index.html, Prism grammar + ldt theme, CLAUDE.md cheat sheet, FILES.md.
  Full decision log (including rejected alternatives `(@path)`, `@(@name)`,
  `${}`/`#{}`/`%()` sigils) in HISTORY.md.

### 22. README + CAVEATS merged into the GitHub Pages doc site ✅
- `docs/index.html` (the GitHub Pages site, `ldt-lang.syncroze.com`)
  is now the complete language reference: every section of `README.md`
  (data model, assignment/interpolation, conditionals, loops, expressions,
  filters, escaping, feeding data in, strict mode, run it, editor support,
  what's-not-possible, excluded features, status) plus every section of
  `CAVEATS.md` (all numbered edge cases, §1–§13), converted to semantic
  HTML with real `<table>`s, heading `id`s, and an in-page table of
  contents. `CAVEATS.md` was deleted (superseding entry #21's plan of
  README as the sole doc — the reference doc moved from README to the
  Pages site instead).
- `README.md` trimmed to just the title, tagline, pipeline one-liner, and
  a prominent link to the docs site — no quickstart/run instructions.
- Cross-references updated: `CLAUDE.md`'s authoritative-documents list,
  `src/Expr.php`'s doc-comment, `FILES.md`'s README/CAVEATS/docs entries,
  and `HISTORY.md`'s "Where things stand" section now point at
  `docs/index.html` instead of `CAVEATS.md`/README's removed sections.
  Historical log entries referencing `CAVEATS.md` (e.g. #18–#21) are left
  as-is since they're a record of what happened at the time, not a
  current-state claim.

### 21. Single-file HTML doc retired; content merged into README ✅
- `docs/ldt-lang.html` (standalone styled HTML page + JS syntax highlighter)
  was first ported 1:1 to `docs/ldt-lang.md`, then found to duplicate
  README.md by ~75% (same rules, reformatted). Rather than maintain a
  fourth near-duplicate doc alongside README/CAVEATS/TASKS, the file was
  dropped and its few genuinely-missing pieces were folded into
  `README.md` instead: the Number/String/Array/undefined classification
  table, a standalone Strict mode section, the "What is not possible"
  table, and the "Deliberately excluded features" table.
- `FILES.md` updated to drop the removed file.
  `HISTORY.md`'s "Where things stand" updated to say README is
  the reference doc. Historical log entries referencing the old
  `ldt-lang.html` filename (#18–#20) are left as-is since they're a record
  of what happened at the time, not a current-state claim.

### 20. Ninth audit batch ✅ (editor grammar, docs/examples hygiene)
- **Grammar rewritten** (`editor/phpstorm/ldtlang.tmbundle`): escapes matched
  first (`\[if` no longer lights up as a tag); markers accept whitespace
  before `]`; filter chains highlighted (`| name:`); `[if]`/`[elseif]`/
  `[for]`/`[unset]` headers are regions with `@refs`, quoted strings, signed
  numbers and word operators colored; symbol operators no longer color plain
  prose. Editor README scope table updated to match.
- **`[unset]` example coverage added** (`examples/assignments.ldt`) — was the
  only construct with no example.
- **Three stale `or`-fallback references purged** from `docs/ldt-lang.html`
  (quoted-values note, `default:` filter row, strict-mode paragraph).
- **CAVEATS sections reordered** into numeric order (10 → 11 → 12 → 13).

### 19. Eighth audit batch ✅ (environment paths, comments, --strict)
- **First fully clean round — no code changes.** Every probe returned
  consistent behavior with pointed errors and exact coordinates (strict
  errors inside `[set]` values, unterminated comments in block values,
  `[unset]` of loop variables, `.0`/`.00` collisions, negative keys).
- Documented six gaps: numeric segments are int indexes (seeded string key
  `'01'` unreachable); scalar-over-map replaces wholesale (loud only in the
  descend direction); seeded `null` is an empty scalar; negative-key append
  starts at 0; `[#]` is unterminated (minimal comment is `[##]`); filter
  args — including a `default:` fallback — are never strict-guarded.

### 18. Seventh audit batch ✅ (Trimmer, block structure, range edges)
- **Range bounds are overflow-checked** — a literal or `@ref` bound beyond the
  platform integer range is a loud error (PHP's `(int)` silently saturates);
  ranges ending exactly at `PHP_INT_MAX`/`PHP_INT_MIN` now terminate (the
  increment used to overflow to float and loop forever, exhausting memory).
- **Ranges stream lazily** — no more materializing every value up front:
  a huge range costs time, never memory; `loop.count` is arithmetic.
- **Wrong-kind closers name the open block** — `[if 1]a[/for]` now says
  `unexpected [/for] inside [if] opened at 1:1 (missing [/if]?)` instead of
  the misleading "without matching opener" (kept for true top-level strays).
- **Trimmer keeps exact coordinates** — post-trim TEXT tokens no longer
  report 1:1 (visible in `--tokens`).
- Documented: no nesting/iteration caps (trusted input; CAVEATS §13), escaped
  whitespace doesn't protect a standalone line, `\n`-only line endings,
  0-based range keys, undefined-range-bound vs undefined-array asymmetry.

### 17. Sixth audit batch ✅
- **`\` before end-of-line is literal** (code now matches CAVEATS §2 — a
  trailing prose backslash survives; applies in body text and `"..."`
  expression strings).
- **`loop` rejected as a `[for]` variable name** (the metadata binding made
  it unreachable — silent before, loud error now; user variables named
  `loop` still shadow/restore as documented).
- **`truncate` never splits UTF-8** — byte-count semantics kept, incomplete
  trailing sequence dropped before the suffix (no mbstring dependency).
- Documented: case filters (`upper`/`lower`/`capitalize`) are ASCII-only.

### 16. Fifth audit batch ✅
- **CLI hardened**: unknown `-`-options and extra file arguments are errors
  (a typo'd `--stric` no longer silently renders non-strict); a leading UTF-8
  BOM is stripped (CLI + `renderFile`).
- **`+5` is a Number**: optional leading `+` accepted everywhere the text
  form is classified (compare/truthy/arithmetic/range bounds/filters);
  `+0` is now falsy.
- **Filter args**: a plain `@ref` arg reads raw (arrays can be `default:`
  fallbacks); `default:` evaluates its argument lazily. Scalar-arg filters
  reject arrays loudly. Non-finite seeded floats (`INF`/`NAN`) rejected.
- **Pointed messages** for duplicate `[else]` / `[elseif]`-after-`[else]`.
- Documented: `--strict` guards only `@{}` (CAVEATS §8 rewritten); CLI
  later-wins seeding vs template path conflicts; big-float sci-notation.

### 15. Fourth audit batch ✅
- **Non-empty arrays are truthy** in `[if]`/`not`/`and`/`or` (empty falsy) —
  `[if @items]` now agrees with `default:` everywhere; comparisons unchanged.
- **`\r` is expression whitespace** (CRLF templates safe in multi-line
  conditions); **markers accept whitespace** (`[else ]`, `[break ]`, …).
- **Exact coordinates extended**: parse errors on continuation lines of
  multi-line headers; runtime errors in `[elseif]` conditions point at the
  `[elseif]`. Leftover-reference errors say `unexpected '@b'`, not `'Array'`.
- CLI `--json`: lists replace wholesale (maps still deep-merge). Documented:
  `round`/`abs` float precision; `@a.-1` inside `@()`.

### 14. Third audit batch ✅
- **Exact error coordinates inside [set] values** (base-offset re-lex,
  site-keyed value cache) — errors in mini-template values point at the real
  template line:col. `[break]` boundary documented; seeding non-scalar values
  throws `InvalidArgumentException` with the key path; newlines are
  whitespace in every tag header.

### 13. Second consistency batch ✅
- **Block values are mini-templates** — a `[set]` value renders exactly like
  body text ([if]/[for]/self-closing [set] always execute; no more
  presence-of-`@{` heuristic). `\[if` for literal tags; nested block sets and
  tags in the `=` form fail loudly.
- **`or` fallback removed** — `default:` covers it and more (expression args,
  variables, cascading). Old syntax fails loudly; logical `or` unchanged.
- **Filter arity enforced**; **`count`/`defined` context-sensitive** (no
  reserved words left); **`--set` keys validated** as dot-paths.

### 12. Quoted-string consistency ✅
- One quoting story everywhere: expression strings (`[if]`/`@()`/filter args)
  now support escapes inside `"..."` (`\"`, `\\`; `\` before letters literal)
  and may span newlines; block-form `[set]` bodies starting with `"` are
  quote-aware, so quotes protect a literal `[/set]`. Comments remain raw
  (quotes never protect `#]` — use `\#]`; documented).

### 11. Uniform `]` closer for `[set = ]` ✅ (hard break)
- `[set path = value]` closes with plain `]` like every other tag; `/]` has no
  meaning anywhere anymore (`\/]` escape retired). Quotes protect `]`
  (`[set b = "a ] b"]` — no escape); unquoted values write `\]`; trailing
  slashes are free (`http://x.com/`). Old quoted `"x"/]` errors loudly; the
  whole repo (tests/examples/docs) was migrated in the same pass.

### 10. `[unset]` ✅
- `[unset path]` / `[unset a, b.c]` — removes each dot-path entirely (the name
  becomes **undefined**, subtree included; never "empty"). Idempotent no-op on
  missing / through-scalar paths; trailing dot errors; `[unsettle]` stays
  literal; standalone lines trim. Index removal leaves holes (no reindex).
  Reset idiom: `[unset g][set g.k = v]`.

### 9. Unified falsy rule ✅
- Booleans textualize as `1`/`0` everywhere (output, flags, comparison
  operands, filter args, `loop.first`/`loop.last`, seeded `false`; seeded
  `null` stays empty). `or` and `default:` now trigger on **falsy** — the same
  rule as `[if]` (undefined, empty, numeric zero, empty array) — so all three
  always agree. `007`/`"0x"` are truthy and pass. A genuine `0` can't survive
  an attached fallback (accepted, Twig-style).

### 8. Quoted [set] values (strict rule) ✅
- A set value wrapped in `"..."` keeps its edge spaces (outer quotes stripped);
  interior quotes escape as `\"`; a value starting with an unescaped `"` that
  is not properly quoted is a **loud error** (never silent mangling); `\"...`
  stores literal quotes. Applies to both set forms and to quoted
  `or`-fallbacks (which also cook their escapes).

### 7. Optimization pass ✅ (no behavior change)
- Lexer: bulk literal-text scanning via `strcspn` (construct checks only at
  `\`/`@`/`[`), bulk `advanceBy` line/col tracking, `strspn`/`ctype` instead of
  per-char regex. Plain-text 500KB: **278ms → 2.4ms (~118×)**.
- Interpreter: memoized `[set]`-value parses (accumulator loop 11.2ms → 2.0ms).
- Dedup: `Environment::segmentsOf()` is now the single dot-path validator
  (was copied in Lexer/ExprParser/ForHeader); `lookup`/`defined` delegate to
  `lookupRaw`; dead `Lexer::readUntil` removed.

### 6. Substring operators ✅
- `contains`, `starts with`, `ends with` — comparison-level operators in `[if]`
  and `@()`. Case-sensitive, byte-wise, on the text form; negate with `not`.
- Context-sensitive words (recognized only in operator position), so they are
  NOT reserved — `[if @x == contains]` still compares the literal.

---

## Audit surface map (2026-07-05)

Nine consistency-audit rounds are done (#12–#20 above). This maps where they
dug versus what has never been the focus of a round. A ✅ on a candidate means
its audit ran to completion with no findings left open. **The map is fully
ticked — every candidate area has been audited to completion.**

### Well covered — low expected yield

- Lexer escapes and marker syntax; whitespace/CRLF in tag headers.
- Filter arity, argument types, `default:` laziness, UTF-8-safe `truncate`.
- Truthiness/falsy uniformity across `[if]` / `not` / `and` / `or` / `default:`.
- Error coordinates: multi-line headers, `[set]` values, `[elseif]` branches.
- CLI flag validation, BOM stripping, `--json` merge semantics.
- Number text-form classification (`+5`, `+0`, `007`, seeded `INF`/`NAN`).

### Candidate areas — roughly by expected yield

1. ✅ **Trimmer** *(seventh audit — clean apart from coordinate drift, fixed;
   escaped-whitespace and CR-only line endings documented)* — standalone
   directive lines with CRLF, first/last line without trailing newline,
   multiple tags on one line, comment-only lines, `--no-trim` parity,
   trimming inside `[set]` block mini-templates.
2. ✅ **Structural parsing at block boundaries** *(seventh audit — wrong-kind
   closer messages fixed; deep-nesting limit documented)* — unclosed
   `[if]`/`[for]`/block-`[set]` at EOF (message + coordinates),
   `[break]`/`[continue]` outside a loop, wrong-closer pairing
   (`[/if]` closing a `[for]`), deep nesting.
3. ✅ **Environment path semantics** *(eighth audit — code clean; numeric-key
   normalization, scalar-over-map replace, seeded null, negative-key append
   documented)* — trailing-dot append onto a scalar, `PathConflict` in every
   direction (template vs seeded, append vs map), `[unset]` holes interacting
   with `loop.count`/`first`/`last`, negative indexes outside `@()`, numeric
   string keys vs int keys after JSON seeding.
4. ✅ **Comments** *(eighth audit — code clean; `[#]`/`[##]` documented)* —
   multi-line `[# #]` coordinate accuracy (unterminated case), comments
   inside `[set]` block values, comment lines and the trimmer.
5. ✅ **Range loop edges** *(seventh audit — two real bugs found and fixed:
   `PHP_INT_MAX` overflow infinite loop, eager materialization OOM)* —
   `by 0`, backwards ranges, `@ref` bounds that are non-integer or arrays,
   very large ranges.
6. ✅ **`--strict` interactions** *(eighth audit — code clean; unguarded
   filter/`default:` args documented)* — strict inside `[set]` mini-template
   values, strict + filter chains without `default`, strict + `loop.*` in
   odd spots.
7. ✅ **Editor grammar drift** *(ninth audit — grammar rewritten: escapes,
   marker whitespace, filters, header regions, word operators, signed
   numbers; README table synced)* — `editor/phpstorm/ldtlang.tmbundle` had
   never been re-synced against language changes.
8. ✅ **Docs/examples cross-check** *(ninth audit — `[unset]` example added,
   three stale `or`-fallback refs purged from the HTML, CAVEATS sections
   reordered; everything else agreed)* — do `examples/*.ldt` exercise every
   feature; do CAVEATS/README/HTML/CLAUDE.md agree?

Items 1 + 2 + 5 shipped as the seventh audit (#18); 3 + 4 + 6 as the eighth
(#19); 7 + 8 as the ninth (#20). Nothing on the map remains open.

---

## Not planned — deliberately excluded to keep it simple

- **Includes / partials** (`[include "..."]`) — not now.
- **Function macros** (`[macro] … [/macro]`) — would turn it into a programming
  language.
- **`switch` / `case`** — `[if]` / `[elseif]` already covers it.
- **Custom / host-registered filters** — a built-in filter set only; letting the
  host app register its own filters is out of scope (changes the API surface).
- **Boolean / null literals** — the two-type model already covers them: flags
  are `1`/empty, undefined is the null. Adding `true`/`false` keywords would
  change the meaning of existing bareword comparisons.
- **Regex** — a complexity cliff (second syntax, `\`-escaping clash); the
  substring operators (`contains` / `starts with` / `ends with`) cover the
  realistic template needs. Validation belongs in the host app.
- **Ternary `?:`** — inline `[if]…[else]…[/if]` and the `default:` filter
  already cover it; `:` is also taken by filter args.

---

## Deferred — revisit later

- Nothing currently deferred. The audit surface map is fully ticked; new
  audit rounds only make sense if new surface area is added.
