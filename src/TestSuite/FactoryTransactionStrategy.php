<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\Fixture\FixtureStrategyInterface;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use Exception;

/**
 * Fixture strategy that uses transactions with automatic table tracking
 *
 * This strategy automatically tracks which tables are written to by fixture
 * factories and wraps them in transactions that are rolled back after each test.
 * It also resets the unique generator state to prevent accumulation.
 *
 * Unlike the standard TransactionStrategy, this doesn't require manually listing
 * fixtures - it automatically detects which tables were used via FactoryTableTracker.
 *
 * Usage (CakePHP 5.2+):
 * Configure globally in config/app.php:
 * ```php
 *     'TestSuite' => [
 *         'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy::class,
 *     ],
 * ```
 *
 * Usage (CakePHP 5.0 - 5.1):
 * Use the FactoryTransactionTrait in your test cases.
 */
class FactoryTransactionStrategy implements FixtureStrategyInterface
{
    /**
     * Active connections with transactions
     *
     * @var array<string, \Cake\Database\Connection>
     */
    protected array $connections = [];

    /**
     * @inheritDoc
     */
    public function setupTest(array $fixtureNames): void
    {
        // Start transactions on all configured connections
        // We do this upfront since we don't know which connections
        // the factories will use until they persist data
        $this->startTransactions();

        // Clear any previously tracked tables
        FactoryTableTracker::getInstance()->clear();

        // Reset generator unique state
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * @inheritDoc
     */
    public function teardownTest(): void
    {
        // Rollback all active transactions
        foreach ($this->connections as $connection) {
            if ($connection->inTransaction()) {
                $connection->rollback(true);
            }
        }

        // Clear connections
        $this->connections = [];

        // Clear tracked tables
        FactoryTableTracker::getInstance()->clear();

        // Reset generator unique state for next test
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Start transactions on all configured database connections
     *
     * @return void
     */
    protected function startTransactions(): void
    {
        $connectionNames = ConnectionManager::configured();

        foreach ($connectionNames as $name) {
            try {
                $connection = ConnectionManager::get($name);

                if (!($connection instanceof Connection)) {
                    continue;
                }

                // Skip if already in transaction
                if ($connection->inTransaction()) {
                    continue;
                }

                // Enable savepoints for nested transaction support
                $connection->enableSavePoints();

                // Begin transaction
                $connection->begin();

                // Create a savepoint that we can rollback to
                $connection->createSavePoint('__fixture_factories__');

                $this->connections[$name] = $connection;
            } catch (Exception $e) {
                // Skip connections that can't be accessed or don't support transactions
                continue;
            }
        }
    }
}
