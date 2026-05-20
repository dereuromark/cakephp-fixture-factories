<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\Fixture\FixtureStrategyInterface;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use RuntimeException;
use Throwable;

/**
 * Fixture strategy that uses transactions with automatic table tracking.
 *
 * The default mode is *eager*: setupTest() opens a transaction on the
 * primary test connection (`test` by default — override the
 * `$primaryConnection` property in a subclass if you use a different
 * connection name) so that direct table operations during a test
 * (`$table->save($entity)`, `$table->delete($entity)`, raw inserts via
 * `$connection->execute(...)`) are also rolled back at teardown — not
 * just operations that go through a Factory's `save()` / `saveMany()`.
 *
 * Beyond the primary connection, additional connections are still
 * tracked lazily: ensureTransaction() is called from inside
 * BaseFactory::save() / saveMany() the first time a Factory persists
 * on a given connection. Multi-database setups therefore only pay
 * the transaction cost on connections they actually write to.
 *
 * Opt-out paths:
 *
 * - For a whole suite that persists exclusively through Factories
 *   (and doesn't want the eager wrap on the primary connection at
 *   all), use {@see LazyFactoryTransactionStrategy} as the global
 *   `'fixtureStrategy'`.
 * - For a single test class that should run lazily while the rest of
 *   the suite stays eager, `use` {@see LazyTransactionTrait} on that
 *   class. Its `#[Before]` hook releases the eager-begun transaction
 *   before the test body runs; subsequent Factory persists will then
 *   lazily begin a fresh one.
 *
 * The strategy automatically tracks which tables are written to by
 * fixture factories via {@see FactoryTableTracker}, so it does not
 * require the manual fixture lists that the standard
 * `TransactionStrategy` does. It also resets the unique-generator
 * state at teardown to prevent collision-prone accumulation between
 * tests.
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
     * Connection name that setupTest() eagerly wraps in a transaction.
     *
     * Override in a subclass when your project uses a different name for
     * its primary test connection. Set to an empty string to disable the
     * eager begin entirely (see {@see LazyFactoryTransactionStrategy}).
     *
     * @var string
     */
    protected string $primaryConnection = 'test';

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
        BaseFactory::resetDefaultGenerator();

        // Eagerly wrap the primary test connection so direct
        // $table->save() / raw insert calls inside a test are also
        // rolled back at teardown. Subclasses that want fully lazy
        // behaviour set $primaryConnection = '' (or override this
        // method). See LazyFactoryTransactionStrategy.
        if ($this->primaryConnection === '') {
            return;
        }
        try {
            /** @var \Cake\Database\Connection $primary */
            $primary = ConnectionManager::get($this->primaryConnection);
        } catch (Throwable) {
            return; // unconfigured / unknown connection name — silent skip.
        }
        $this->ensureTransaction($primary);
    }

    /**
     * @inheritDoc
     */
    public function teardownTest(): void
    {
        // try/finally so a rollback failure inside releaseTransactions()
        // (now aggregated and rethrown) still clears the active instance
        // and the table tracker — otherwise the next test inherits dead
        // bookkeeping pointing at a half-released strategy.
        try {
            $this->releaseTransactions();
        } finally {
            self::$activeInstance = null;
            FactoryTableTracker::getInstance()->clear();
            CakeGeneratorFactory::clearInstances();
            BaseFactory::resetDefaultGenerator();
        }
    }

    /**
     * Roll back and forget every connection currently tracked by the
     * strategy — including the eager-begun primary connection.
     *
     * Called from teardownTest() unconditionally, and exposed publicly
     * so that {@see LazyTransactionTrait} can release the eager begin
     * mid-setup for a single test class. After release, subsequent
     * Factory persists will lazily begin a fresh transaction on the
     * connection they actually write to.
     *
     * @return void
     */
    public function releaseTransactions(): void
    {
        // Per-connection isolation: if rollback throws on connection N
        // (broken socket, killed backend, server-side timeout), connections
        // N+1.. still get rolled back, and `$this->connections` resets
        // *regardless*. The previous abort-on-first-failure semantics left
        // open transactions on every subsequent connection AND left this
        // strategy's bookkeeping pointing at dead connections — leaking the
        // active instance and silently corrupting the next test.
        $errors = [];
        foreach ($this->connections as $name => $connection) {
            if (!$connection->inTransaction()) {
                continue;
            }
            try {
                $connection->rollback(true);
            } catch (Throwable $e) {
                $errors[] = sprintf("'%s': %s", $name, $e->getMessage());
            }
        }
        $this->connections = [];

        if ($errors !== []) {
            throw new RuntimeException(
                'releaseTransactions: rollback failed on ' . implode('; ', $errors)
                . '. Strategy state has been reset; subsequent tests may still need attention.',
            );
        }
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
