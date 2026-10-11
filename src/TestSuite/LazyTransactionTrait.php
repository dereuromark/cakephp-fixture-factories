<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

/**
 * Per-test-class opt-out of the eager priming the default
 * {@see FactoryTransactionStrategy} performs in `setupTest()`.
 *
 * Use this when your suite is mostly direct-save (the eager default
 * is fine globally) but a handful of test classes either persist
 * exclusively through Factories or otherwise want to skip the eager
 * transaction wrap on the primary connection — for example, because
 * they explicitly start their own transaction inside the test, or
 * because they want to observe direct-write side effects from a
 * separate connection.
 *
 * Add the trait to a CakePHP test class. It releases the eager transaction
 * immediately after CakePHP installs the fixture strategy, before the
 * application's setUp() creates test data. Factory saves in setUp() or
 * the test body then start a fresh transaction for teardown to roll back.
 *
 * Cost: one extra `BEGIN` / `ROLLBACK` pair on the primary connection
 * for every test in the using class. Negligible at typical suite
 * scale.
 *
 * If your whole suite needs lazy behaviour, use
 * {@see LazyFactoryTransactionStrategy} as the global strategy
 * instead — it skips the eager begin in `setupTest()` entirely.
 *
 * Example:
 *
 * ```php
 * use CakephpFixtureFactories\TestSuite\LazyTransactionTrait;
 *
 * class HeavyConnectionTest extends \Cake\TestSuite\TestCase
 * {
 *     use LazyTransactionTrait;
 *
 *     // Tests in this class run with the lazy contract:
 *     // a connection only joins the rollback set when a Factory
 *     // persists on it.
 * }
 * ```
 *
 * @since 2.0.0
 */
trait LazyTransactionTrait
{
    /**
     * Release the eager transaction before application test setup continues.
     *
     * @return void
     */
    protected function setupFixtures(): void
    {
        parent::setupFixtures();
        $this->releaseEagerTransactionForTest();
    }

    /**
     * Release transactions owned by the active factory fixture strategy.
     *
     * @return void
     */
    public function releaseEagerTransactionForTest(): void
    {
        FactoryTransactionStrategy::getActiveInstance()?->releaseTransactions();
    }
}
