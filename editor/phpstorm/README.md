# PhpStorm syntax highlighting for ldt-lang (`.ldt`)

PhpStorm ships with built-in TextMate support, so a small TextMate bundle
gives real (regex-based) highlighting — including `[= ]` emit tags,
`[set]` directives and `[# #]` comments — without writing a plugin.

## Install (TextMate bundle — recommended)

1. **Settings/Preferences** → **Editor** → **TextMate Bundles**.
2. Click **+** and select this folder:
   `editor/phpstorm/ldtlang.tmbundle`
3. Click **OK** / **Apply**.

`.ldt` files are highlighted automatically (the grammar registers the
`ldt` extension). Adjust colors under **Editor → Color Scheme → TextMate**
if you want to tweak how each scope looks.

### What gets highlighted
| Construct | Scope |
|-----------|-------|
| escapes (`\[if`, `\[=`, `\#]`, …) | `constant.character.escape` |
| `[set` / `[/set]` / `[unset` | `keyword.control` |
| write names / `@ref` reads / loop vars | `variable.other` |
| `=` | `keyword.operator.assignment` |
| the value between `=`/`]` and its closer | `string.unquoted.value` |
| `[= expr]` emit tags | `meta.emit` (contents via the rows below) |
| `[if` / `[elseif` / `[else]` / `[/if]` | `keyword.control` |
| `[for` / `[/for]` / `[break]` / `[continue]` | `keyword.control` |
| word operators (`and or not defined count contains starts with ends in to by`) | `keyword.operator.word` |
| symbol operators (`== != < > <= >= + - * / %`) — inside headers/`[= ]` only | `keyword.operator` |
| `\| filter` names in `[= ]` chains | `support.function.filter` |
| `"quoted strings"` in expressions | `string.quoted.double` |
| numbers (incl. `+5` / `-5`) | `constant.numeric` |
| `[# ... #]` comments | `comment.block` |

Markers accept whitespace before `]` (`[else ]`), and escapes are matched
first so `\[if` never lights up as a live tag — both mirroring the engine.

## Alternative: native File Type (no bundle)

Quicker but weaker — it's keyword/brace based and can't properly color
`[= ]` contents:

1. **Settings** → **Editor** → **File Types** → **+** (New).
2. Name `ldtlang`, block comment `[#` … `#]`.
3. Keywords (level 1): `[set`, `[/set]`.
4. In **File name patterns**, add `*.ldt`.

Prefer the TextMate bundle unless you specifically want the native option.
