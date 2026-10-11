---
title: Getting Started
description: Quick start guide for CakePHP Fixture Factories
---

# Getting Started

Bake your first factory and use it in a test after installing the plugin.

## What is this?

CakePHP Fixture Factories replaces the static `$fixtures` array workflow with a programmatic, type-safe factory pattern, like FactoryBot (Ruby) or factory_boy (Python), tailored to CakePHP and the wider PHP ecosystem.

You write a factory class per table; tests then build exactly the data they need:

```php
$articles = ArticleFactory::new()
    ->count(5)
    ->with('Authors[3].Address.City.Country')
    ->saveMany();
```

Five articles, each with three authors, each with a full address chain, persisted, in one expression.

## Before you start

Complete [Installation](/guide/installation) first. This walkthrough assumes an existing CakePHP application with an `Articles` table containing `id`, `title` and `body`, its `ArticlesTable` class, and the same schema in the separate `test` database. Run your application's test migrations before saving factory data. Factory baking reads the application schema; it does not create tables or migrations.

Use [Setup](/guide/setup) to configure database cleanup between tests. With CakePHP 5.2 or newer, the global `FactoryTransactionStrategy` is the easiest option. On CakePHP 5.0 or 5.1, add `FactoryTransactionTrait` to the test class.

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

A complete test with the factory transaction trait:

```php
namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\ArticleFactory;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;

class ArticlesTableTest extends TestCase
{
    use FactoryTransactionTrait;

    public function testSavedArticle(): void
    {
        $article = ArticleFactory::new(['title' => 'Hello'])->save();

        $this->assertFalse($article->isNew());
        $this->assertSame('Hello', $article->title);
        $this->assertNotEmpty($article->id);
    }
}
```

Save this as `tests/TestCase/Model/Table/ArticlesTableTest.php` and run:

```bash
vendor/bin/phpunit tests/TestCase/Model/Table/ArticlesTableTest.php
```

The transaction strategy rolls the inserted article back after the test. If your suite already configures the strategy globally, omit the trait.

## Where to next?

- [Fixture Factories](factories) — full factory API
- [Usage Examples](examples) — common patterns
- [Associations](associations) — building object graphs
- [Scenarios](scenarios) — reusable test setups
- [Generators](generators) — switching between Faker and DummyGenerator
- [Bake command](/reference/bake) and [Persist command](/reference/persist) — CLI reference
