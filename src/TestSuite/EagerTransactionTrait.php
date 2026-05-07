<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\Attributes\Before;
use Throwable;

/**
 * Per-test-class opt-in to eager transactional semantics on top of the
 * default lazy {@see FactoryTransactionStrategy}.
 *
 * Use this when your suite is mostly Factory-pipeline (the lazy default
 * is fine globally) but a handful of test classes intentionally persist
 * outside the Factory — typically
 * `Factory::new()->build()->toArray()` followed by
 * `$this->Foo->save($entity)` to validate via the table layer — and
 * those direct writes need to be rolled back too.
 *
 * `use` the trait in the affected test class. The `#[Before]` hook
 * runs after the active strategy's `setupTest()` has installed
 * itself, then primes a transaction on the eager connection.
 *
 * The eager connection defaults to the one resolved by
 * `ConnectionManager::get($eagerConnection)` (`$eagerConnection`
 * defaults to `'test'`). Override `$eagerConnection` to point at a
 * different connection name, or override `getEagerConnections()`
 * for full control — for example, to pass a Connection instance
 * obtained from a specific table or to prime several connections.
 *
 * If your whole suite needs the eager wrap, use
 * {@see EagerFactoryTransactionStrategy} as the global strategy
 * instead.
 *
 * Example:
 *
 * ```php
 * use CakephpFixtureFactories\TestSuite\EagerTransactionTrait;
 *
 * class ApiUsersTableTest extends \Cake\TestSuite\TestCase
 * {
 *     use EagerTransactionTrait;
 *
 *     public function testValidationDefault(): void
 *     {
 *         $data = ApiUserFactory::new()->build()->toArray();
 *         $apiUser = $this->ApiUsers->newEntity($data);
 *         $this->assertTrue((bool)$this->ApiUsers->save($apiUser));
 *         // Direct save above is rolled back at teardown.
 *     }
 * }
 * ```
 */
trait EagerTransactionTrait
{
    /**
     * Connection name to eagerly wrap on each test in the using class.
     *
     * Override in the using class when your project's primary test
     * connection is named something other than `test`. Set to an empty
     * string to disable the prime entirely.
     *
     * @var string
     */
    protected string $eagerConnection = 'test';

    /**
     * Resolve the connection(s) to wrap eagerly. Default implementation
     * resolves `$this->eagerConnection` via `ConnectionManager::get()`.
     *
     * Override in the using class for full control — for example, to
     * pass a Connection instance obtained directly from a Table the
     * test uses (avoiding any alias-map ambiguity in the test app's
     * bootstrap), or to prime several connections at once.
     *
     * @return list<\Cake\Database\Connection>
     */
    protected function getEagerConnections(): array
    {
        if ($this->eagerConnection === '') {
            return [];
        }
        try {
            /** @var \Cake\Database\Connection $connection */
            $connection = ConnectionManager::get($this->eagerConnection);
        } catch (Throwable) {
            return []; // unconfigured / unknown connection name — silent skip.
        }

        return [$connection];
    }

    /**
     * Prime a transaction on each eager connection before the test runs.
     *
     * Runs as a PHPUnit `#[Before]` hook, which fires after the
     * fixture strategy's `setupTest()` has set the active instance,
     * but before the test method body — and before any `assertPre*`
     * hooks would observe the connection state.
     *
     * @return void
     */
    #[Before]
    public function ensureEagerTransactionForTest(): void
    {
        $strategy = FactoryTransactionStrategy::getActiveInstance();
        if ($strategy === null) {
            return;
        }
        foreach ($this->getEagerConnections() as $connection) {
            if (!$connection instanceof Connection) {
                continue;
            }
            $strategy->ensureTransaction($connection);
        }
    }
}
