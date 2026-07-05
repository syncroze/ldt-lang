# ldt-lang — project instructions for Claude

## Working rules (from the user, standing)

- **Never implement without explicit confirmation.** Questions ("can this
  work…", "what if…", "how about…") are questions — answer, explain, propose,
  then WAIT for a clear go-ahead ("do it", "implement", "let's build it").
  Design discussions come first; code only after sign-off.
- Explain trade-offs before updating when asked ("explain before updating").

## What this project is

**ldt-lang** ("Logic Driven Text Language") — an embeddable templating
language interpreted in PHP (no Composer required), `.ldt` files. Pipeline:
`Lexer → Trimmer → Parser → Interpreter`, namespace `Ldtlang\`, facade
`Ldt::render($source, $data = [], $strict = false, $file = null, $trim = true)`,
CLI `bin/ldt` (`--set k=v`, `--json f`, `--strict`, `--tokens`, `--no-trim`).

## Language cheat sheet (current, complete)

THE RULE: `[...]` = construct, `@path` = READ (only inside constructs),
`[= expr]` = evaluate-and-emit, `\` = escape; everything else is literal text.
Writes take a BARE name; reads carry the `@`. `@` in text is ALWAYS literal
(emails/handles safe by construction; `@{}`/`@()` no longer exist).

- `[set path = value]` / `[set path]value[/set]` — write target is bare (no @);
  plain `]` closer; values are trimmed; VALUES ARE MINI-TEMPLATES
  ([= ]/[if]/[for]/self-closing [set] execute inside them); the `=` form scans
  BRACKET-AWARE (counts `[ ]` pairs, so `[set total = [= @a + 1]]` nests;
  an UNPAIRED literal `[`/`]` needs `\[`/`\]`); dot-paths nest (`a.b.c`),
  trailing dot appends (`items.`); quote a whole value to keep edge spaces
  (strict rule; `\"` for literal quotes). Purely numeric segments are INT
  indexes (`.01` ≡ `.1`); scalar-over-map replaces wholesale (loud only when
  descending INTO a scalar)
- `[unset a, b.c]` — bare names; removes paths entirely (undefined again);
  no-op if missing; index removal leaves holes
- `[= expr | filters]` — THE emit tag (replaced `@{path}` and `@(expr)`):
  plain `[= @path]` interpolates (strict-guarded, booleans textualize `1`/`0`,
  arrays render ''); computed expressions: integer `+ - * / %`, comparisons,
  `and/or/not`, `defined @x`, `count @x`, `contains`/`starts with`/`ends with`.
  Fallbacks: `| default: expr` (falsy-triggered, same rule as `[if]`; cascades;
  LAZY arg; a plain `@ref` arg reads raw, so an array can be the fallback).
  No nesting `[= ]` in `[= ]` (use `( )`); not usable as a range bound.
- Filters ONLY in `[= ]` (NOT tag conditions): `value | filter: arg, arg`;
  full-expression args; set: upper lower trim capitalize truncate join first
  last round abs html default.
- `[if]/[elseif]/[else]/[/if]`, `[for k, v in @arr]` / `[for n in 1 to 5 by 2]`
  (loop vars bare, iterable/bounds are `@refs`), `[break]`, `[continue]`,
  `[= @loop.index/.index0/.first/.last/.count]`. Argument-less markers accept
  whitespace before `]`. Ranges STREAM LAZILY (huge range = time, not memory);
  bounds outside the platform int range are loud errors; range keys are
  0-based positions.
- Falsy = undefined / empty string / numeric zero / empty array; a NON-empty
  array is truthy in `[if]`/`not`/`and`/`or` (comparisons read arrays as '').
- `[# comment #]`; `\` escapes any non-alphanumeric char; standalone directive
  lines are trimmed ([= ] lines are output, never trimmed).
- Two data types (String/Number by text form), nested arrays, undefined = null.
- Booleans textualize as `1`/`0` (flags, `loop.first/last`, seeded `false`);
  seeded `null` stays empty.

## Authoritative documents (read before proposing changes)

- `HISTORY.md` — full decision log: what was chosen, what was
  rejected and WHY (do not re-propose rejected designs: bare `@` in text,
  regex, ternary, bool/null literals, custom filters, includes, macros).
- `TASKS.md` — roadmap: Done / Not planned / Deferred.
- `docs/index.html` (also live at https://syncroze.github.io/ldt-lang/docs/) —
  full language reference and every edge case/gotcha (the "Caveats" section).
- `tests/run.php` — 390 zero-dependency tests; run with `php tests/run.php`.
  Every change must keep this green and all `examples/*.ldt` rendering.
