<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;

/**
 * Test that the FactoryTransactionStrategy properly manages transactions
 * and tracks tables used by factories
 */
class FactoryTransactionStrategyTest extends TestCase
{
    /**
     * We don't use the FactoryTransactionTrait here because we're testing
     * the strategy itself and need more control
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we're starting with a clean state
        FactoryTableTracker::getInstance()->clear();
    }

    protected function tearDown(): void
    {
        FactoryTableTracker::getInstance()->clear();

        parent::tearDown();
    }

    /**
     * Test that FactoryTableTracker records tables when factories persist
     *
     * @return void
     */
    public function testTableTrackingRecordsTables(): void
    {
        $tracker = FactoryTableTracker::getInstance();
        $tracker->clear();

        $this->assertFalse($tracker->hasTables(), 'Tracker should start empty');

        // Persist a city
        CityFactory::make()->persist();

        $this->assertTrue($tracker->hasTables(), 'Tracker should have tables after persist');
        $tables = $tracker->getTableNames();
        $this->assertContains('cities', $tables, 'Tracker should include cities table');
    }

    /**
     * Test that multiple factories track multiple tables
     *
     * @return void
     */
    public function testTableTrackingRecordsMultipleTables(): void
    {
        $tracker = FactoryTableTracker::getInstance();
        $tracker->clear();

        // Persist cities and countries
        CityFactory::make()->persist();
        CountryFactory::make()->persist();

        $tables = $tracker->getTableNames();
        $this->assertContains('cities', $tables);
        $this->assertContains('countries', $tables);
        $this->assertCount(2, $tables, 'Should track 2 distinct tables');
    }

    /**
     * Test that tracker can be cleared
     *
     * @return void
     */
    public function testTableTrackerClear(): void
    {
        $tracker = FactoryTableTracker::getInstance();

        CityFactory::make()->persist();
        $this->assertTrue($tracker->hasTables());

        $tracker->clear();
        $this->assertFalse($tracker->hasTables());
        $this->assertEmpty($tracker->getTableNames());
    }

    /**
     * Test that FactoryTransactionStrategy uses lazy transactions
     *
     * Transactions are NOT started upfront in setupTest(), but only when
     * a factory actually persists data.
     *
     * @return void
     */
    public function testTransactionStrategyLazySetup(): void
    {
        $strategy = new FactoryTransactionStrategy();

        // Get the connection the factory will actually use
        $connection = CityFactory::make()->getTable()->getConnection();

        // Setup should NOT start transactions (lazy strategy)
        $strategy->setupTest([]);

        $this->assertFalse(
            $connection->inTransaction(),
            'Connection should NOT be in transaction after setup (lazy)',
        );

        // The active instance should be set
        $this->assertSame($strategy, FactoryTransactionStrategy::getActiveInstance());

        // Persist data — this should lazily start the transaction
        $city = CityFactory::make(['name' => 'Test City'])->persist();
        $this->assertNotEmpty($city->id);

        $this->assertTrue(
            $connection->inTransaction(),
            'Connection should be in transaction after persist',
        );

        // Verify table is tracked
        $tracker = FactoryTableTracker::getInstance();
        $this->assertTrue($tracker->hasTables());
        $this->assertContains('cities', $tracker->getTableNames());

        // Teardown should rollback and clear
        $strategy->teardownTest();

        $this->assertFalse($tracker->hasTables());
        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }

    /**
     * Test that teardown without any persists works fine
     *
     * @return void
     */
    public function testTeardownWithoutPersist(): void
    {
        $strategy = new FactoryTransactionStrategy();

        $strategy->setupTest([]);
        // No persist calls — teardown should still work
        $strategy->teardownTest();

        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }

    /**
     * Test that tracker continues to work across multiple persist calls
     *
     * @return void
     */
    public function testTrackerWorksAcrossMultiplePersists(): void
    {
        $tracker = FactoryTableTracker::getInstance();
        $tracker->clear();

        // Create some data
        $city1 = CityFactory::make(['name' => 'City 1'])->persist();
        $city2 = CityFactory::make(['name' => 'City 2'])->persist();

        $this->assertNotEmpty($city1->id);
        $this->assertNotEmpty($city2->id);

        // Verify tracking is working
        $this->assertTrue($tracker->hasTables());
        $this->assertContains('cities', $tracker->getTableNames());
    }

    /**
     * Test that the same table written multiple times is only tracked once
     *
     * @return void
     */
    public function testSameTableTrackedOnce(): void
    {
        $tracker = FactoryTableTracker::getInstance();
        $tracker->clear();

        // Persist multiple cities
        CityFactory::make()->persist();
        CityFactory::make()->persist();
        CityFactory::make()->persist();

        $tables = $tracker->getTableNames();
        $this->assertCount(1, $tables, 'Same table should only be tracked once');
        $this->assertContains('cities', $tables);
    }

    /**
     * Regression: under FactoryTransactionStrategy, persisted entities must
     * report `isNew() === false` and be clean immediately after `persist()`.
     *
     * CakePHP 5.4 defers `$entity->setNew(false)` / `$entity->clean()` /
     * `$entity->setSource()` to a `Connection::afterCommit()` callback when
     * the save runs inside an outer transaction. Our strategy never commits
     * (always rolls back at teardown), so without compensation the returned
     * entity would still report `isNew() === true` — silently breaking
     * `Table::delete()` and any other `isNew()`-aware code in tests.
     *
     * Pre-5.4 CakePHP ran this synchronously inside `save()`; this test
     * passes there for that reason.
     *
     * @return void
     */
    public function testPersistFinalizesEntityStateUnderOuterTransaction(): void
    {
        $strategy = new FactoryTransactionStrategy();
        $strategy->setupTest([]);

        try {
            $city = CityFactory::make(['name' => 'Berlin'])->persist();

            $this->assertNotEmpty($city->id, 'Entity should have an id after persist');
            $this->assertFalse(
                $city->isNew(),
                'Persisted entity must not report isNew() === true; '
                . 'a true value here would short-circuit subsequent Table::delete() calls.',
            );
            $this->assertFalse($city->isDirty(), 'Persisted entity must be clean.');
            $this->assertSame(
                'Cities',
                $city->getSource(),
                'Persisted entity must carry its registry alias.',
            );

            // Connection is in transaction (lazy-started by persist),
            // mirroring the runtime conditions of the bug.
            $connection = CityFactory::make()->getTable()->getConnection();
            $this->assertTrue(
                $connection->inTransaction(),
                'Sanity check: the bug only manifests inside an outer transaction.',
            );
        } finally {
            $strategy->teardownTest();
        }
    }
}
