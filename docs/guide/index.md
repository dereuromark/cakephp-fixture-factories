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

In `src/Application.php`:

```php
protected function bootstrapCli(): void
{
    if (Configure::read('debug')) {
        $this->addPlugin('CakephpFixtureFactories');
    }
}
```

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
