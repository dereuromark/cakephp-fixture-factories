---
title: Getting Started
description: Quick start guide for CakePHP Fixture Factories
---

# Getting Started

This guide walks you through installing the plugin, baking your first factory, and saving test data.

## What is this?

CakePHP Fixture Factories replaces the static `$fixtures` array workflow with a programmatic, type-safe factory pattern — like FactoryBot (Ruby) or factory_boy (Python), tailored to CakePHP and the wider PHP ecosystem.

You write a factory class per table; tests then build exactly the data they need:

```php
$articles = ArticleFactory::new()
    ->count(5)
    ->with('Authors[3].Address.City.Country')
    ->saveMany();
```

Five articles, each with three authors, each with a full address chain — persisted, in one expression.

## Installation

```bash
composer require --dev dereuromark/cakephp-fixture-factories
```

You'll also need a data generator. Pick one (or install both):

```bash
# Faker — mature, full locale support
composer require --dev fakerphp/faker

# DummyGenerator — lean, PHP 8.3+, native enums
composer require --dev johnykvsky/dummygenerator
```

## Load the plugin

This plugin is dev-only — factories live under `tests/Factory/` and are never
called from production code paths. Pick the loading pattern that matches how
strict you want to be about that:

::: code-group

```bash [Quickest — loads everywhere]
bin/cake plugin load CakephpFixtureFactories
```

```php [Manual plugins.php — loads everywhere]
// config/plugins.php
return [
    'CakephpFixtureFactories' => [],
];
```

```php [Dev-only via bootstrapCli() — recommended for prod-bound apps]
// src/Application.php
protected function bootstrapCli(): void
{
    if (Configure::read('debug')) {
        $this->addPlugin('CakephpFixtureFactories');
    }
}
```

:::

The `bin/cake plugin load` command edits `config/plugins.php` for you and is
the easiest path. Both that and the manual `plugins.php` form load the plugin
in all environments — fine if your production deploy strips dev dependencies,
but if there's any chance dev deps end up on a production box, prefer the
`bootstrapCli()` form: it gates loading on CLI plus `debug` mode, so the
plugin is never bootstrapped in production HTTP requests.

## Configure the fixture strategy (recommended)

The plugin ships with `FactoryTransactionStrategy` — wraps each test in a transaction, rolls everything back afterwards, resets unique generator state. No more `$fixtures` arrays.

In `config/app.php`:

```php
'TestSuite' => [
    'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy::class,
],
```

For older CakePHP versions (5.0 – 5.1) use the trait — see [Setup](setup).

## Bake your first factory

```bash
bin/cake bake fixture_factory Articles -m
```

The `-m` flag adds helper methods for each association. The generated `tests/Factory/ArticleFactory.php`:

```php
namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class ArticleFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Articles';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'title' => $generator->text(30),
            'body'  => $generator->text(1000),
        ];
    }
}
```

## Use it

```php
use App\Test\Factory\ArticleFactory;

// Build (in memory only)
$article = ArticleFactory::new()->build();

// Or save to DB
$article = ArticleFactory::new()->save();

// With overrides
$article = ArticleFactory::new(['title' => 'Hello'])->save();

// With associations
$article = ArticleFactory::new()
    ->hasAuthors(3)
    ->save();

// Multiple entities
$articles = ArticleFactory::new()->count(5)->saveMany();
```

That's it — your test now has data without touching a fixture file.

## Where to next?

- [Fixture Factories](factories) — full factory API
- [Usage Examples](examples) — common patterns
- [Associations](associations) — building object graphs
- [Scenarios](scenarios) — reusable test setups
- [Generators](generators) — switching between Faker and DummyGenerator
- [Bake command](/reference/bake) and [Persist command](/reference/persist) — CLI reference
