<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\Datasource\ConnectionManager;

/**
 * Eager variant of {@see FactoryTransactionStrategy}.
 *
 * Opens a transaction on the primary test connection (`test` by
 * default — override the `$primaryConnection` property in a subclass
 * if you use a different connection name) up-front in `setupTest()`,
 * so direct table operations during a test (`$table->save($entity)`,
 * `$table->delete($entity)`, raw inserts via
 * `$connection->execute(...)`) are also rolled back at teardown — not
 * just operations that go through a Factory's `save()` / `saveMany()`.
 *
 * Beyond the primary connection, additional connections are still
 * tracked lazily by the parent strategy: `ensureTransaction()` is
 * called from inside {@see \CakephpFixtureFactories\Factory\BaseFactory::save()}
 * / `saveMany()` the first time a Factory persists on a given
 * connection.
 *
 * Use this strategy when:
 *
 * - Some of your tests intentionally persist outside the Factory
 *   pipeline — typically `Factory::new()->build()->toArray()` followed
 *   by `$this->Foo->save($entity)` to validate via the table layer —
 *   and you want those writes covered by the rollback too.
 * - You don't mind opening a transaction on the primary connection on
 *   every test, even ones that never persist.
 *
 * If neither applies, prefer the default
 * {@see FactoryTransactionStrategy}, which is fully lazy: a connection
 * only joins the rollback set when a Factory actually persists on it.
 *
 * For a single test class that needs eager semantics while the rest
 * of your suite stays lazy, see {@see EagerTransactionTrait} — same
 * effect, scoped to one test class via a `use` statement, no global
 * config change needed.
 *
 * Usage (CakePHP 5.2+):
 * Configure globally in config/app.php:
 * ```php
 *     'TestSuite' => [
 *         'fixtureStrategy' => \CakephpFixtureFactories\TestSuite\EagerFactoryTransactionStrategy::class,
 *     ],
 * ```
 */
class EagerFactoryTransactionStrategy extends FactoryTransactionStrategy
{
    /**
     * Connection name that setupTest() eagerly wraps in a transaction.
     *
     * Override in a subclass when your project uses a different name
     * for its primary test connection. Set to an empty string to
     * disable the eager begin (effectively reverting to the parent's
     * lazy behavior).
     *
     * @var string
     */
    protected string $primaryConnection = 'test';

    /**
     * @inheritDoc
     */
    public function setupTest(array $fixtureNames): void
    {
        parent::setupTest($fixtureNames);

        if ($this->primaryConnection === '') {
            return;
        }

        // Note: we resolve via ConnectionManager::get() which honours the
        // alias map. We do NOT pre-check ConnectionManager::getConfig()
        // because that returns null for alias names whose source is
        // registered under a different key — the connection still exists,
        // it's just keyed under the alias's target.
        try {
            /** @var \Cake\Database\Connection $primary */
            $primary = ConnectionManager::get($this->primaryConnection);
        } catch (\Throwable) {
            return; // unconfigured / unknown connection name — silent skip.
        }
        $this->ensureTransaction($primary);
    }
}
