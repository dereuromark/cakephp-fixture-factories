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

> **Note:** Table tracking only captures tables written via the factory save methods. The transaction rollback still handles **all** data modifications regardless of source (factories, controllers, models, etc.) **on connections a Factory has persisted on during the test**. Connections that no Factory has touched are not in the rollback set — see "Lazy by default" below.

### Lazy by default

`FactoryTransactionStrategy` is **lazy**: a connection joins the rollback set the first time a Factory persists on it (via `BaseFactory::save()` / `saveMany()`, which calls the strategy's `ensureTransaction()` under the hood). Connections a given test never writes to via a Factory pay no transaction cost.

This works perfectly when your tests persist exclusively through Factories. It does **not** automatically cover writes that bypass the Factory pipeline — typically the testFind / testValidationDefault pattern:

```php
public function testValidationDefault(): void
{
    $data = ArticleFactory::new()->build()->toArray();   // build only, no Factory persist
    $article = $this->Articles->newEntity($data);
    $this->assertTrue((bool)$this->Articles->save($article));  // direct table save, NOT in the rollback set
}
```

The direct `$this->Articles->save($article)` runs outside any transaction the strategy has started. Its row stays in the test database after teardown. Subsequent test methods that persist `articles` may collide on a unique constraint with the leaked row, especially when `unique()` generators reset between tests.

There are two ways to opt into eager priming so direct table saves are also covered:

#### `EagerFactoryTransactionStrategy` (whole suite)

Point `'fixtureStrategy'` at the eager variant. It opens a transaction on the primary test connection (`test` by default; configurable via the `$primaryConnection` property) up-front in `setupTest()`. Other connections are still tracked lazily.

```php
'TestSuite' => [
    'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\EagerFactoryTransactionStrategy::class,
],
```

Use this when your suite has the testFind pattern broadly and you don't want to track which classes are affected.

#### `EagerTransactionTrait` (per test class)

Use the trait in just the test classes that mix Factory build with direct table saves. The rest of the suite stays on the lazy default.

```php
use CakephpFixtureFactories\TestSuite\EagerTransactionTrait;

class ApiUsersTableTest extends \Cake\TestSuite\TestCase
{
    use EagerTransactionTrait;

    public function testValidationDefault(): void
    {
        $data = ApiUserFactory::new()->build()->toArray();
        $apiUser = $this->ApiUsers->newEntity($data);
        $this->assertTrue((bool)$this->ApiUsers->save($apiUser));
        // Direct save above is rolled back at teardown — the trait
        // primed a transaction on `test` via a #[Before] hook.
    }
}
```

If your project's primary test connection is named something other than `test`, override `$eagerConnection` (trait) or `$primaryConnection` (strategy subclass) accordingly.
