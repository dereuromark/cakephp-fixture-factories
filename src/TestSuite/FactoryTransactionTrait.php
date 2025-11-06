<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\TestSuite\Fixture\FixtureStrategyInterface;

/**
 * Trait for test cases that use fixture factories with automatic transaction management
 *
 * Add this trait to your test case to automatically:
 * - Track which tables are written to by fixture factories
 * - Wrap database operations in transactions
 * - Rollback after each test
 * - Reset unique generator state
 *
 * CakePHP 5.2+ Note:
 * This trait is only needed for CakePHP 5.0 - 5.1. In CakePHP 5.2+, you can configure
 * the strategy globally instead:
 *
 * ```php
 * // config/app.php or tests/bootstrap.php
 * return [
 *     'TestSuite' => [
 *         'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy::class,
 *     ],
 * ];
 * ```
 *
 * Important notes:
 * - Table tracking only captures tables written via factory->persist()
 * - Transaction rollback handles ALL data (factory and application code)
 * - Unique generator state is reset regardless of table usage
 *
 * Example (CakePHP 4.3 - 5.1):
 * ```php
 * use Cake\TestSuite\TestCase;
 * use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;
 *
 * class MyTest extends TestCase
 * {
 *     use FactoryTransactionTrait;
 *
 *     public function testSomething()
 *     {
 *         // Factory data is tracked and rolled back
 *         $article = ArticleFactory::make()->persist();
 *
 *         // Application code saves are also rolled back (but not tracked)
 *         $this->Articles->save($article);
 *
 *         // All data is rolled back, generator state is reset
 *     }
 * }
 * ```
 */
trait FactoryTransactionTrait
{
    /**
     * Get the fixture strategy for this test case
     *
     * @return \Cake\TestSuite\Fixture\FixtureStrategyInterface
     */
    public function getFixtureStrategy(): FixtureStrategyInterface
    {
        return new FactoryTransactionStrategy();
    }
}
