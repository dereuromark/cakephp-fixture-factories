<h1 align="center">Setup</h1>

## Non-CakePHP apps
For non-CakePHP applications, you may use the method proposed by your framework
to manage the test database, or opt for the universal
[test database cleaner](https://github.com/vierge-noire/test-database-cleaner).

You should define your DB connections in your test `bootstrap.php` file as described
in the [cookbook](https://book.cakephp.org/5/en/orm/database-basics.html#configuration).

## CakePHP apps

To be able to bake your factories,
load the CakephpFixtureFactories plugin in your `plugins.php` file or your `src/Application.php` file:
```php
protected function bootstrapCli(): void
{
    // Load more plugins here
    if (Configure::read('debug')) {
        $this->addPlugin('CakephpFixtureFactories');
    }
}
```

**We recommend using migrations for managing the schema of your test DB with the [CakePHP Migrator tool.](https://book.cakephp.org/migrations/2/en/index.html#using-migrations-for-tests)**

## Table Truncation

Generated fixtures usually must be removed from the database in between tests in order to avoid collisions between entities, which can cause unexpected test results.
There are several ways to manage this behavior when using fixtures and fixture factories.

CakePHP ships with [Fixture State Managers](https://book.cakephp.org/5/en/development/testing.html#fixture-state-managers) and provides the `TruncateStrategy`
(truncate all tables after test run) as well as the `TransactionStrategy` (create a transaction and roll it back after each test run).

The [CakePHP test suite light plugin](https://github.com/vierge-noire/cakephp-test-suite-light#cakephp-test-suite-light) provides the `TriggerStrategy`
which will set up a trigger in your database to clean up the tables after each test run.
This might require admin/root permission access, so this is not necessarily possible on all setups.
```php
// In config/app.php
    'TestSuite' => [
        'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\TriggerStrategy::class,
    ],
```

#### Factory Transaction Strategy (Recommended)

This plugin provides the `FactoryTransactionStrategy` which automatically:
- Wraps **all** database operations in transactions
- Rolls back after each test (both factory and application data)
- **Resets unique generator state** (fixes OverflowException issues)
- Tracks which tables are written to by fixture factories

Unlike the standard `TransactionStrategy`, this doesn't require manually listing fixtures.
In fact: This strategy works best if you do not use fixtures, at all.

##### CakePHP 5.2+ (Global Configuration)

In CakePHP 5.2+, you can configure the fixture strategy globally in your `config/app.php`:

```php
    'TestSuite' => [
        'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy::class,
    ],
```

This applies the strategy to **all test cases** automatically. No traits needed!

> **Tip**: See `config/app.example.php` in this plugin for a full list of all available configuration options, including generator type, seed, and instance-level generator management.

##### CakePHP 5.0 - 5.1 (Trait-based)

For older CakePHP versions, use the trait in your test cases:

```php
use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;

class MyTest extends TestCase
{
    use FactoryTransactionTrait;

    public function testSomething()
    {
        // Factory data - automatically tracked and rolled back
        $article = ArticleFactory::make()->persist();

        // Application code saves - also rolled back (but not tracked)
        $this->Articles->save($article);

        // ALL data is rolled back, generator state is reset
    }
}
```

Alternatively, create a base test class with the trait:

```php
namespace App\Test;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;

abstract class AppTestCase extends TestCase
{
    use FactoryTransactionTrait;
}
```

Then extend it in all your tests:

```php
class MyTest extends AppTestCase
{
    // Strategy is automatically applied
}
```

##### Benefits

- No need to manually list `$fixtures`
- **All data is rolled back** - factory data AND application code modifications
- Faster than truncation strategies
- **Solves unique generator state accumulation** - the strategy resets generator state after each test, preventing `OverflowException` when using `unique()` modifiers
- Works seamlessly with nested associations

**Note:** Table tracking only captures tables written via `factory->persist()`. However, the transaction rollback handles ALL data modifications regardless of source (factories, controllers, models, etc.).

**We recommend using migrations for managing the schema of your test DB with the [CakePHP Migrator tool.](https://book.cakephp.org/migrations/3/en/index.html#using-migrations-for-tests)**



