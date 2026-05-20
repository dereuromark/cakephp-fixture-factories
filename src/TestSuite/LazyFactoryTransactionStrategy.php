<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

/**
 * Lazy variant of {@see FactoryTransactionStrategy}.
 *
 * A connection only joins the rollback set when a Factory actually
 * persists on it — `ensureTransaction()` is called per-connection from
 * inside {@see \CakephpFixtureFactories\Factory\BaseFactory::save()} /
 * `saveMany()` the first time the connection is written to. Tests that
 * never write to a configured-but-untouched connection skip the
 * transaction cost entirely.
 *
 * Use this strategy when:
 *
 * - Your tests persist exclusively through Factories — no direct
 *   `$table->save($entity)`, no raw inserts via `$connection->execute()`
 *   inside test methods.
 * - You have multiple test connections and want to skip transactions
 *   on the ones a given test does not touch.
 *
 * If either of those is not true, prefer the default
 * {@see FactoryTransactionStrategy}, which eagerly wraps the primary
 * test connection in setupTest(). That covers direct table operations
 * inside tests, which the lazy variant intentionally does not.
 *
 * For a single test class that should run lazily while the rest of
 * the suite stays on the eager default, `use`
 * {@see LazyTransactionTrait} on that class instead of swapping the
 * global strategy.
 *
 * Usage (CakePHP 5.2+):
 * Configure globally in config/app.php:
 * ```php
 *     'TestSuite' => [
 *         'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\LazyFactoryTransactionStrategy::class,
 *     ],
 * ```
 *
 * @since 2.0.0
 */
class LazyFactoryTransactionStrategy extends FactoryTransactionStrategy
{
    /**
     * Disable the eager begin() in {@see FactoryTransactionStrategy::setupTest()}.
     *
     * @var string
     */
    protected string $primaryConnection = '';
}
