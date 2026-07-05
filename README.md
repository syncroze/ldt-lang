# ldt-lang

**Logic Driven Text Language** — an embeddable templating language interpreted
in PHP. Like PHP, it is written *inside* arbitrary text: everything is literal
until a bracket construct appears. Files use the `.ldt` extension.

The engine is a four-stage `Lexer → Trimmer → Parser → Interpreter` pipeline
with a bracket-tag surface syntax and a **nested** data model.

**Full documentation, syntax reference, and caveats:
[syncroze.github.io/ldt-lang](https://syncroze.github.io/ldt-lang/)**
