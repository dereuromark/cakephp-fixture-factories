<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\Database\Connection;
use Cake\TestSuite\Fixture\FixtureStrategyInterface;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use Throwable;

/**
 * Fixture strategy that uses transactions with automatic table tracking
 *
 * This strategy automatically tracks which tables are written to by fixture
 * factories and wraps them in transactions that are rolled back after each test.
 * It also resets the unique generator state to prevent accumulation.
 *
 * Transactions are started lazily — only on connections that are actually used
 * during a test, rather than on all configured connections upfront.
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
     * The currently active strategy instance
     *
     * @var self|null
     */
    private static ?self $activeInstance = null;

    /**
     * @inheritDoc
     */
    public function setupTest(array $fixtureNames): void
    {
        // Store the active instance so BaseFactory can access it
        self::$activeInstance = $this;

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

        // Clear active instance
        self::$activeInstance = null;

        // Clear tracked tables
        FactoryTableTracker::getInstance()->clear();

        // Reset generator unique state for next test
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Ensure a transaction is active on the given connection.
     *
     * Called from BaseFactory save operations before persistence, so transactions
     * are only started on connections that are actually used.
     *
     * @param \Cake\Database\Connection $connection The connection to ensure a transaction on
     *
     * @return void
     */
    public function ensureTransaction(Connection $connection): void
    {
        $name = $connection->configName();

        if (isset($this->connections[$name])) {
            return;
        }

        if ($connection->inTransaction()) {
            return;
        }

        try {
            $connection->enableSavePoints();
            $connection->begin();
            $this->connections[$name] = $connection;
        } catch (Throwable $e) {
            // Failing silently here would let subsequent factory saves persist
            // real data outside any transaction, leaking across tests with no
            // signal. Fail loud instead — a broken transaction strategy is a
            // setup error, not a recoverable condition.
            throw new PersistenceException(
                "Failed to start transaction on connection `{$name}`: {$e->getMessage()}",
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Get the currently active strategy instance, if any.
     *
     * @return self|null
     */
    public static function getActiveInstance(): ?self
    {
        return self::$activeInstance;
    }
}
