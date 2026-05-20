<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use PHPUnit\Framework\Attributes\Before;

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
 * `use` the trait in the affected test class. The `#[Before]` hook
 * runs after the active strategy's `setupTest()` has primed the
 * primary connection, then immediately rolls that transaction back
 * via {@see FactoryTransactionStrategy::releaseTransactions()}.
 * Subsequent `BaseFactory::save()` / `saveMany()` calls inside the
 * test will lazily begin a fresh transaction via
 * `ensureTransaction()`.
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
     * Release the eager-begun transaction the active strategy started
     * in `setupTest()`.
     *
     * Runs as a PHPUnit `#[Before]` hook, which fires after the
     * fixture strategy's `setupTest()` has installed itself and
     * begun the eager transaction, but before the test method body —
     * so the released transaction never sees test traffic.
     *
     * @return void
     */
    #[Before]
    public function releaseEagerTransactionForTest(): void
    {
        FactoryTransactionStrategy::getActiveInstance()?->releaseTransactions();
    }
}
