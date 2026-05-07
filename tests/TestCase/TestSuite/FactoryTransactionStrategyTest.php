<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\FakerAdapter;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\TestSuite\EagerFactoryTransactionStrategy;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use RuntimeException;

/**
 * Test that the FactoryTransactionStrategy properly manages transactions
 * and tracks tables used by factories
 */
class FactoryTransactionStrategyTest extends TestCase
{
    use TruncateDirtyTables;

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
        Configure::delete('FixtureFactories.generatorType');
        BaseFactory::resetDefaultGenerator();

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
        CityFactory::new()->save();

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
        CityFactory::new()->save();
        CountryFactory::new()->save();

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

        CityFactory::new()->save();
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
        $connection = CityFactory::new()->getTable()->getConnection();

        // Setup should NOT start transactions (lazy strategy)
        $strategy->setupTest([]);

        $this->assertFalse(
            $connection->inTransaction(),
            'Connection should NOT be in transaction after setup (lazy)',
        );

        // The active instance should be set
        $this->assertSame($strategy, FactoryTransactionStrategy::getActiveInstance());

        // Persist data — this should lazily start the transaction
        $city = CityFactory::new(['name' => 'Test City'])->save();
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
     * EagerFactoryTransactionStrategy primes the primary test connection
     * inside setupTest() so direct $table->save() calls inside a test
     * are also covered by the rollback. Beyond that, behavior matches
     * the parent (lazy on additional connections).
     *
     * @return void
     */
    public function testEagerStrategyWrapsPrimaryConnection(): void
    {
        // Use the connection name CityFactory's table reports so the test
        // exercises the eager wrap on the same connection instance the
        // existing lazy test already relies on. Hardcoding 'test' would
        // be brittle if a downstream test app uses a different alias.
        $connection = CityFactory::new()->getTable()->getConnection();
        $strategy = new class ($connection->configName()) extends EagerFactoryTransactionStrategy {
            public function __construct(string $name)
            {
                $this->primaryConnection = $name;
            }
        };

        $strategy->setupTest([]);

        $this->assertTrue(
            $connection->inTransaction(),
            'Connection should be in transaction after eager setup',
        );
        $this->assertSame($strategy, FactoryTransactionStrategy::getActiveInstance());

        $strategy->teardownTest();

        $this->assertFalse(
            $connection->inTransaction(),
            'Connection should be released after teardown',
        );
        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }

    /**
     * Setting $primaryConnection to '' on an EagerFactoryTransactionStrategy
     * subclass disables the eager begin and reverts to the parent's lazy
     * behavior on the primary connection. Useful when callers want eager
     * defaults but no priming for a particular project shape.
     *
     * @return void
     */
    public function testEagerStrategyWithoutPrimaryConnectionStaysLazy(): void
    {
        $strategy = new class () extends EagerFactoryTransactionStrategy {
            protected string $primaryConnection = '';
        };
        $connection = CityFactory::new()->getTable()->getConnection();

        $strategy->setupTest([]);

        $this->assertFalse(
            $connection->inTransaction(),
            'Connection should NOT be in transaction when $primaryConnection is empty',
        );

        $strategy->teardownTest();
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

    public function testStrategyResetsSharedDefaultGenerator(): void
    {
        Configure::write('FixtureFactories.generatorType', 'faker');
        BaseFactory::setDefaultGenerator('dummy');

        $strategy = new FactoryTransactionStrategy();
        $strategy->setupTest([]);

        $generator = CityFactory::new()->getGenerator();

        $this->assertInstanceOf(FakerAdapter::class, $generator);

        $strategy->teardownTest();
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
        $country = CountryFactory::new(['name' => 'Tracked country'])->save();
        $tracker->clear();

        // Create some data
        $city1 = CityFactory::new(['name' => 'City 1', 'country_id' => $country->id])
            ->without('Countries')
            ->save();
        $city2 = CityFactory::new(['name' => 'City 2', 'country_id' => $country->id])
            ->without('Countries')
            ->save();

        $this->assertNotEmpty($city1->id);
        $this->assertNotEmpty($city2->id);

        // Verify tracking is working
        $this->assertTrue($tracker->hasTables());
        $this->assertContains('cities', $tracker->getTableNames());
    }

    /**
     * Regression: when two Tables on different connections share the same SQL
     * table name (multi-tenant, read/write split), both must be tracked. The
     * previous flat `[name => connection]` storage collapsed them; nested
     * `[connection => [name => true]]` keeps both.
     *
     * @return void
     */
    public function testCrossConnectionSameTableNameKeepsBoth(): void
    {
        $tracker = FactoryTableTracker::getInstance();
        $tracker->clear();

        $firstConnection = $this->createConfiguredMock(Connection::class, [
            'configName' => 'test',
        ]);
        $secondConnection = $this->createConfiguredMock(Connection::class, [
            'configName' => 'second_conn',
        ]);
        $firstTable = $this->createConfiguredMock(Table::class, [
            'getTable' => 'cities',
            'getConnection' => $firstConnection,
        ]);
        $secondTable = $this->createConfiguredMock(Table::class, [
            'getTable' => 'cities',
            'getConnection' => $secondConnection,
        ]);

        $tracker->trackTable($firstTable);
        $tracker->trackTable($secondTable);

        $byConn = $tracker->getTablesByConnection();
        $this->assertArrayHasKey('test', $byConn);
        $this->assertArrayHasKey('second_conn', $byConn);
        $this->assertContains('cities', $byConn['test']);
        $this->assertContains('cities', $byConn['second_conn']);
        // Flat dedupe view returns one row, but the nested view sees both.
        $this->assertCount(1, $tracker->getTableNames());
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
        $country = CountryFactory::new(['name' => 'Tracked country'])->save();
        $tracker->clear();

        // Persist multiple cities
        CityFactory::new(['name' => 'City 1', 'country_id' => $country->id])->without('Countries')->save();
        CityFactory::new(['name' => 'City 2', 'country_id' => $country->id])->without('Countries')->save();
        CityFactory::new(['name' => 'City 3', 'country_id' => $country->id])->without('Countries')->save();

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
            $city = CityFactory::new(['name' => 'Berlin'])->save();

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
            $connection = CityFactory::new()->getTable()->getConnection();
            $this->assertTrue(
                $connection->inTransaction(),
                'Sanity check: the bug only manifests inside an outer transaction.',
            );
        } finally {
            $strategy->teardownTest();
        }
    }

    public function testEnsureTransactionRethrowsPersistenceException(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('configName')->willReturn('failing');
        $connection->method('inTransaction')->willReturn(false);
        $connection->expects($this->once())->method('enableSavePoints');
        $connection->expects($this->once())
            ->method('begin')
            ->willThrowException(new RuntimeException('no transaction'));

        $strategy = new FactoryTransactionStrategy();

        try {
            $strategy->ensureTransaction($connection);
            $this->fail('Expected PersistenceException to be thrown.');
        } catch (PersistenceException $exception) {
            $this->assertSame('no transaction', $exception->getPrevious()?->getMessage());
        }
    }
}
