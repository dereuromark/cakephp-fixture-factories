---
title: Configuration Reference
description: All FixtureFactories config keys
---

# Configuration Reference

All settings live under the `FixtureFactories` key in your `config/app.php` (or `tests/bootstrap.php`). A complete annotated example ships with the plugin at [`config/app.example.php`](https://github.com/dereuromark/cakephp-fixture-factories/blob/main/config/app.example.php).

```php
return [
    'FixtureFactories' => [
        'generatorType' => 'faker',
        'defaultLocale' => 'en_US',
        'seed' => 1234,
        'instanceLevelGenerator' => true,
        'strictDefinition' => true,
        'autoSkipComposeOnExplicitForeignKey' => true,
        'testFixtureNamespace' => 'App\\Test\\Factory',
        'testFixtureOutputDir' => 'Factory/',
        'testFixtureGlobalBehaviors' => [],
        'defaultDataMap' => [],
        'columnPatterns' => [],
    ],
];
```

## Keys

### `generatorType`

Generator adapter to use for fake data.

- `'faker'` — [FakerPHP/Faker](https://github.com/FakerPHP/Faker). Requires `composer require --dev fakerphp/faker`.
- `'dummy'` — [johnykvsky/dummygenerator](https://github.com/johnykvsky/dummygenerator). Requires `composer require --dev johnykvsky/dummygenerator`. PHP 8.3+.

When this key is unset, the plugin auto-detects which library is installed.
Precedence: explicit `Configure::write('FixtureFactories.generatorType', ...)`
beats auto-detect; auto-detect prefers `'faker'` when both libraries are
installed and falls back to `'dummy'` when only DummyGenerator is present.
If neither library is installed a `FixtureFactoryException` is thrown with
installation guidance.

See [Generators](/guide/generators) for the full comparison.

### `defaultLocale`

Default locale for the generator. Falls back to `I18n::getLocale()` when not set.

> Faker has full locale support; DummyGenerator's locale support is limited.

### `seed`

Seed for the generator's RNG. A fixed seed produces reproducible test data across runs. Default: `1234`.

### `instanceLevelGenerator`

When `true`, `setGenerator()` only affects the current factory instance instead of globally changing the generator for all factories. Use `BaseFactory::setDefaultGenerator()` to set the global default explicitly.

Default: `true` (`setGenerator()` only affects the current factory instance).

> Set this to `false` only if you explicitly need the legacy global `setGenerator()` behavior.

### `strictDefinition`

Controls the FK-in-`definition()` detector. When `true` (the default), any
factory whose `definition()` returns a column belonging to a belongsTo
association emits `E_USER_DEPRECATED` pointing at the offending column and the
association. Foreign-key columns should be populated by composing the parent
association (`->with('Alias')`, `->for(...)`, or a factory helper such as
`forAuthor()` / `withAuthor()`), never as a scalar default.

Set to `false` only when migrating a legacy test suite away from the pattern —
the opt-out is transitional and will be removed in the next major release,
when the deprecation becomes a hard exception. See
[Foreign keys in `definition()`](/guide/foreign-keys-in-definition) for the
full rationale and a migration cookbook.

Default: `true`.

### `autoSkipComposeOnExplicitForeignKey`

Controls whether a `configure()`-composed `belongsTo` parent is automatically
skipped for a build when the caller explicitly supplies that association's
foreign-key column.

When `true` (the default) and a factory composes a parent in `configure()`
(e.g. `configure()->with('Homes')`), a call site that pins the FK —
`Factory::new(['home_id' => 123])`, `->setField('home_id', 123)`,
`->state(['home_id' => 123])`, or `sequence()` — makes the factory **not**
compose that parent for that build (an implicit `->without('Homes')`). The
explicitly-set `123` then survives instead of being silently overwritten by
the composed parent's freshly-persisted id, and no throw-away parent row is
created. This is almost always the intent and complements the
[FK-in-`definition()` detector](/guide/foreign-keys-in-definition): the
detector says "don't put the FK in `definition()`", this makes "pin the FK at
the call site" Just Work.

An explicit `->with('Alias', ...)` always wins over the auto-skip — the caller
clearly asked for composition, so it is never skipped even if the FK is also
set. Only `configure()` defaults are auto-skipped, and only when the FK value
comes from caller-supplied state (not a `definition()` default) and is
non-null.

Set to `false` to restore the legacy behavior where a `configure()`-composed
parent overrides an explicitly-set foreign key (e.g. for a suite that relied
on that override).

Default: `true`.

### `testFixtureNamespace`

Namespace where factory classes live. Auto-detected from the table registry name when not set.

Default: `App\\Test\\Factory`.

### `testFixtureOutputDir`

Output directory for baked factory files, relative to `tests/`.

Default: `Factory/`.

### `testFixtureGlobalBehaviors`

Behaviors that should be active during fixture creation. The `Timestamp` behavior is always included.

Provide the behavior name only — not the plugin-prefixed form (use `BehaviorName`, not `SomeVendor/WithPluginName.BehaviorName`).

```php
'testFixtureGlobalBehaviors' => ['SomeBehaviorUsedInMultipleTables'],
```

### `defaultDataMap`

Custom data mapping for the bake command. Maps column names/types to generator
method fragments, generator call fragments with arguments, or full generator
calls starting with `$generator->`. Both of these are valid:

```php
'defaultDataMap' => [
    'string' => [
        'sku' => 'ean13',
        'status' => "randomElement(['draft', 'live'])",
    ],
],
```

### `columnPatterns`

Custom column-name regex patterns for the bake command, mapped to generator
method fragments, generator call fragments with arguments, or full generator
calls starting with `$generator->`.

```php
'columnPatterns' => [
    '/^phone/' => 'phoneNumber()',
    '/^zip/'   => 'postcode()',
],
```
