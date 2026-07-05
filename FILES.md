# File structure

Complete file listing for ldt-lang, with a one-line description of each.

## Root

- [`README.md`](README.md) — intro/tagline plus a link to the full documentation site.
- [`CLAUDE.md`](CLAUDE.md) — working rules and project cheat sheet for Claude sessions (auto-loads when working in this repo).
- [`TASKS.md`](TASKS.md) — task/audit log: numbered work batches, audit surface map, deferred items.
- [`HISTORY.md`](HISTORY.md) — full design/decision log: rationale for major choices, alternatives considered and rejected, chronological audit-round writeups.
- [`FILES.md`](FILES.md) — this file.
- [`composer.json`](composer.json) — Composer package metadata (namespace `Ldtlang\`, no runtime dependencies).
- [`autoload.php`](autoload.php) — zero-dependency PSR-4-style autoloader for `src/`.

## [`bin/`](bin/)

- [`bin/ldt`](bin/ldt) — CLI entry point: render a `.ldt` file with optional `--json` data, `--strict`, `--tokens` debug output, etc.

## [`src/`](src/)

- [`Ldt.php`](src/Ldt.php) — public facade; `Ldt::render()` / `Ldt::renderFile()` wire the pipeline together.
- [`Lexer.php`](src/Lexer.php) — tokenizes raw source into text/tag tokens.
- [`Token.php`](src/Token.php) — token value object (type, text, line, col).
- [`Trimmer.php`](src/Trimmer.php) — whitespace/standalone-line handling between lexing and parsing (trims directive-only lines).
- [`Parser.php`](src/Parser.php) — recursive-descent parser building the AST; tracks open-block stack for mismatched-closer errors.
- [`ForHeader.php`](src/ForHeader.php) — parses `[for ... ]` header syntax (iterable/range bounds, loop variable, step).
- [`ForNode.php`](src/ForNode.php) — AST node for `[for]` loops.
- [`IfNode.php`](src/IfNode.php) — AST node for `[if]/[elseif]/[else]` conditionals.
- [`Expr.php`](src/Expr.php) — expression AST/value helpers, including `intInRange()` overflow guard.
- [`ExprParser.php`](src/ExprParser.php) — parses expressions used in `[if]`, `[for]` headers, `@()`.
- [`Interpreter.php`](src/Interpreter.php) — tree-walking evaluator; executes the AST against an `Environment`, handles loops (including lazy range streaming), filters, interpolation.
- [`Environment.php`](src/Environment.php) — data model for template variables: dot-path get/set/unset, nested arrays, numeric-key normalization.
- [`Filters.php`](src/Filters.php) — built-in filter functions (`default`, `truncate`, `count`, etc.) applied via `|`.
- [`BreakSignal.php`](src/BreakSignal.php) — internal exception used to implement `[break]`.
- [`ContinueSignal.php`](src/ContinueSignal.php) — internal exception used to implement `[continue]`.
- [`SyntaxError.php`](src/SyntaxError.php) — parse/lex error type carrying line/col coordinates.
- [`PathConflict.php`](src/PathConflict.php) — error type for invalid `Environment` path operations (e.g. descending into a scalar).

## [`tests/`](tests/)

- [`tests/run.php`](tests/run.php) — zero-dependency test suite (`check()`/`throws()`/`errorAt()` helpers); the full regression suite referenced throughout TASKS.md/HISTORY.md.

## [`examples/`](examples/)

- [`examples/assignments.ldt`](examples/assignments.ldt) — `[set]`/`[unset]` variable assignment and dot-path demos.
- [`examples/conditions.ldt`](examples/conditions.ldt) — `[if]/[elseif]/[else]` conditional demos.
- [`examples/data.ldt`](examples/data.ldt) — rendering seeded data (paired with `data.json`).
- [`examples/data.json`](examples/data.json) — sample JSON data consumed by `data.ldt` via the CLI `--json` flag.
- [`examples/expressions.ldt`](examples/expressions.ldt) — `@()` expression syntax demos (operators, refs, word-operators).
- [`examples/filters.ldt`](examples/filters.ldt) — `|filter` chain demos.
- [`examples/limitations.ldt`](examples/limitations.ldt) — documented edge cases/known limitations demonstrated in template form.
- [`examples/loops.ldt`](examples/loops.ldt) — `[for]` loop demos, including ranges and loop metadata (`loop.count`, etc.).

## [`editor/phpstorm/`](editor/phpstorm/)

- [`editor/phpstorm/README.md`](editor/phpstorm/README.md) — notes on the TextMate grammar bundle and its scope conventions.
- [`editor/phpstorm/ldtlang.tmbundle/info.plist`](editor/phpstorm/ldtlang.tmbundle/info.plist) — TextMate bundle metadata.
- [`editor/phpstorm/ldtlang.tmbundle/Syntaxes/ldtlang.tmLanguage`](editor/phpstorm/ldtlang.tmbundle/Syntaxes/ldtlang.tmLanguage) — TextMate grammar (plist) providing syntax highlighting for `.ldt` files.

## [`docs/`](docs/)

- `docs/.nojekyll` — tells GitHub Pages to skip the Jekyll build (this is a plain static site).
- `docs/index.html` — GitHub Pages site: full language reference and caveats (README + CAVEATS merged), with highlighted syntax samples.
- `docs/assets/ldt-prism.js` — Prism.js grammar approximating `.ldt` syntax for browser-side highlighting.
- `docs/assets/style.css` — minimal styling for the Pages site.
