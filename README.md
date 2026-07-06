# ldt-lang

**Logic Driven Text Language** — an embeddable templating language interpreted
in PHP. Like PHP, it is written *inside* arbitrary text: everything is literal
until a bracket construct appears. Files use the `.ldt` extension.

The engine is a four-stage `Lexer → Trimmer → Parser → Interpreter` pipeline
with a bracket-tag surface syntax and a **nested** data model.

**Full documentation, syntax reference, and caveats:
[ldt-lang.syncroze.com](https://ldt-lang.syncroze.com/)**

## Installation

```bash
composer require syncroze/ldt-lang
```

[syncroze/ldt-lang on Packagist](https://packagist.org/packages/syncroze/ldt-lang)

```php
require 'vendor/autoload.php';
use Ldtlang\Ldt;

echo Ldt::render('Hello [set who = world][= @who]!');
```

The bundled CLI is available at `vendor/bin/ldt`:

```bash
vendor/bin/ldt template.ldt --set who=world
```

No Composer? The engine also runs with plain PHP — see `autoload.php` in the
repository and the [docs site](https://ldt-lang.syncroze.com/) for the
dependency-free setup.
