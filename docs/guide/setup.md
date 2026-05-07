# Setup

## Non-CakePHP apps

For non-CakePHP applications, use whatever method your framework provides for managing the test database, or opt for the universal [test-database-cleaner](https://github.com/vierge-noire/test-database-cleaner).

Define your DB connections in your test `bootstrap.php` as described in the [CakePHP cookbook](https://book.cakephp.org/5/en/orm/database-basics.html#configuration).

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

**We recommend using migrations for managing the schema of your test DB with the [CakePHP Migrations plugin](https://book.cakephp.org/migrations/5/index.html#using-migrations-for-tests).**

## Cleaning data between tests

Test data must be cleaned between tests to avoid entity collisions and unexpected results. CakePHP ships with [Fixture State Managers](https://book.cakephp.org/5/en/development/testing.html#fixture-state-managers) and provides the `TruncateStrategy` (truncate all tables after each test run) as well as the `TransactionStrategy` (create a transaction and roll it back after each test run).

For fixture-factory–driven test suites, this plugin provides a dedicated strategy that pairs cleanly with factories — see below.

## Factory Transaction Strategy (Recommended)

This plugin provides the `FactoryTransactionStrategy` which automatically:
- Wraps **all** database operations in transactions
- Rolls back after each test (both factory and application data)
- **Resets unique generator state** (fixes OverflowException issues)
- Tracks which tables are written to by fixture factories

Unlike the standard `TransactionStrategy`, this doesn't require manually listing fixtures — in fact, the strategy works best if you don't use classic `$fixtures` arrays at all.

### CakePHP 5.2+ (global configuration)

In CakePHP 5.2+, configure the fixture strategy globally in `config/app.php`:

```php
'TestSuite' => [
    'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy::class,
],
```

This applies the strategy to **all test cases** automatically. No traits needed.

> **Tip**: See `config/app.example.php` in this plugin for a full list of available configuration options, including generator type, seed, and instance-level generator management.

### CakePHP 5.0–5.1 (trait-based)

For older CakePHP versions, use `FactoryTransactionTrait`. Two patterns:

::: code-group

```php [Base class (recommended)]
namespace App\Test;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;

abstract class AppTestCase extends TestCase
{
    use FactoryTransactionTrait;
}

// Then extend AppTestCase in every test:
class MyTest extends AppTestCase
{
    // Strategy is automatically applied.
}
```

```php [Per test]
use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;

class MyTest extends TestCase
{
    use FactoryTransactionTrait;

    public function testSomething(): void
    {
        $article = ArticleFactory::new()->save();
        $this->Articles->save($article);
        // All data rolled back; generator state reset.
    }
}
```

:::

Prefer the shared `AppTestCase` route — the per-test trait pattern works too but means repeating yourself.

### Benefits

- No need to manually list `$fixtures`
- **All data is rolled back** - factory data AND application code modifications
- Faster than truncation strategies
- **Solves unique generator state accumulation** - the strategy resets generator state after each test, preventing `OverflowException` when using `unique()` modifiers
- Works seamlessly with nested associations

> **Note:** Table tracking only captures tables written via the factory save methods. The transaction rollback still handles **all** data modifications regardless of source (factories, controllers, models, etc.) on the primary test connection (eager-wrapped at setup) plus any additional connections a Factory has persisted on during the test (tracked lazily). See "Eager by default" below for the full contract.

### Eager by default

`FactoryTransactionStrategy` is **eager** on the primary test connection: `setupTest()` opens a transaction on `test` (configurable via the `$primaryConnection` property) up-front, so direct table operations during the test (`$table->save($entity)`, `$table->delete($entity)`, raw inserts via `$connection->execute(...)`) are also rolled back at teardown — not just operations that go through a Factory's `save()` / `saveMany()`.

Beyond the primary connection, additional connections are still tracked **lazily**: `ensureTransaction()` is called from inside `BaseFactory::save()` / `saveMany()` the first time a Factory persists on a given connection. Multi-database setups therefore only pay the transaction cost on connections they actually write to.

This covers the standard CakePHP testing patterns out of the box:

```php
// Eager-default makes both of these get correctly rolled back:

public function testValidationDefault(): void
{
    $data = ArticleFactory::new()->build()->toArray();
    $article = $this->Articles->newEntity($data);
    $this->assertTrue((bool)$this->Articles->save($article));   // direct table save — covered
}

public function testFind(): void
{
    ArticleFactory::new()->save();                              // Factory persist — covered
    $this->assertNotNull($this->Articles->find()->first());
}
```

#### Opting out

If your suite persists exclusively through Factories and you want to skip the eager begin (multi-connection optimization, performance under heavy parallel test runs):

##### Whole suite

Point `'fixtureStrategy'` at the lazy variant:

```php
'TestSuite' => [
    'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\LazyFactoryTransactionStrategy::class,
],
```

A connection only joins the rollback set when a Factory persists on it.

##### Single test class

Use `LazyTransactionTrait` on just the affected class. The rest of the suite stays eager:

```php
use CakephpFixtureFactories\TestSuite\LazyTransactionTrait;

class HeavyConnectionTest extends \Cake\TestSuite\TestCase
{
    use LazyTransactionTrait;

    // Eager begin from setupTest() is rolled back via #[Before].
    // Tests in this class run with the lazy contract.
}
```

If your project's primary test connection is named something other than `test`, override the `$primaryConnection` property in a `FactoryTransactionStrategy` subclass:

```php
final class MyEagerStrategy extends \CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy
{
    protected string $primaryConnection = 'test_main';
}
```
