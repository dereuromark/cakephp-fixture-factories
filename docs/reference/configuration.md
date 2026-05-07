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

- `'faker'` (default) — [FakerPHP/Faker](https://github.com/FakerPHP/Faker). Requires `composer require --dev fakerphp/faker`.
- `'dummy'` — [johnykvsky/dummygenerator](https://github.com/johnykvsky/dummygenerator). Requires `composer require --dev johnykvsky/dummygenerator`. PHP 8.3+.

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
