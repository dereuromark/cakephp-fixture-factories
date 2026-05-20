<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Factory\DataCompiler;
use CakephpFixtureFactories\Generator\FakerAdapter;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use CakephpFixtureFactories\TestSuite\LazyFactoryTransactionStrategy;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

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
     * Default {@see FactoryTransactionStrategy} eagerly wraps the primary
     * test connection in setupTest() so direct $table->save() calls inside
     * the test are rolled back at teardown alongside Factory operations.
     *
     * @return void
     */
    public function testEagerDefaultWrapsPrimaryConnection(): void
    {
        // The package's test bootstrap aliases connection names such that
        // ConnectionManager::get('test') resolves to the connection
        // registered as 'dummy' in some matrix variants. Inject the exact
        // Connection CityFactory's table reports so begin() and the
        // assertion target the same instance regardless of alias shape.
        $connection = CityFactory::new()->getTable()->getConnection();
        $strategy = new class ($connection) extends FactoryTransactionStrategy {
            public function __construct(private Connection $eagerConnection)
            {
            }

            public function setupTest(array $fixtureNames): void
            {
                $this->primaryConnection = '';
                parent::setupTest($fixtureNames);
                $this->ensureTransaction($this->eagerConnection);
            }
        };

        $strategy->setupTest([]);

        $this->assertTrue(
            $connection->inTransaction(),
            'Connection should be in transaction after eager (default) setup',
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
     * {@see LazyFactoryTransactionStrategy} skips the eager begin in
     * setupTest(); a connection only joins the rollback set after a
     * Factory persists on it via ensureTransaction().
     *
     * @return void
     */
    public function testLazyStrategyDefersTransactionUntilPersist(): void
    {
        $strategy = new LazyFactoryTransactionStrategy();

        $connection = CityFactory::new()->getTable()->getConnection();

        $strategy->setupTest([]);

        $this->assertFalse(
            $connection->inTransaction(),
            'Connection should NOT be in transaction after setup (lazy)',
        );
        $this->assertSame($strategy, FactoryTransactionStrategy::getActiveInstance());

        $city = CityFactory::new(['name' => 'Lazy City'])->save();
        $this->assertNotEmpty($city->id);

        $this->assertTrue(
            $connection->inTransaction(),
            'Connection should be in transaction after persist',
        );

        $tracker = FactoryTableTracker::getInstance();
        $this->assertTrue($tracker->hasTables());
        $this->assertContains('cities', $tracker->getTableNames());

        $strategy->teardownTest();

        $this->assertFalse($tracker->hasTables());
        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }

    /**
     * Setting $primaryConnection to '' on a FactoryTransactionStrategy
     * subclass disables the eager begin — equivalent to
     * LazyFactoryTransactionStrategy.
     *
     * @return void
     */
    public function testEmptyPrimaryConnectionDisablesEagerBegin(): void
    {
        $strategy = new class () extends FactoryTransactionStrategy {
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
     * If `$primaryConnection` is set to an unconfigured/typo'd name (the
     * typical bug: a subclass declares 'test_min' instead of 'test'), the
     * eager guarantee silently disappeared. Now emit an `E_USER_WARNING` so
     * the regression surfaces at runtime — mixed-style tests (Factory +
     * direct `$table->save()`) would otherwise start leaking direct-save rows.
     */
    public function testBadPrimaryConnectionEmitsUserWarning(): void
    {
        $strategy = new class () extends FactoryTransactionStrategy {
            protected string $primaryConnection = 'definitely_not_a_real_connection_name';
        };

        $captured = [];
        set_error_handler(
            function (int $level, string $message) use (&$captured): bool {
                if ($level === E_USER_WARNING) {
                    $captured[] = $message;

                    return true;
                }

                return false;
            },
            E_USER_WARNING,
        );

        try {
            $strategy->setupTest([]);
        } finally {
            restore_error_handler();
            $strategy->teardownTest();
        }

        $this->assertNotEmpty($captured, 'Bad $primaryConnection must trigger an E_USER_WARNING.');
        $this->assertStringContainsString('definitely_not_a_real_connection_name', $captured[0]);
        $this->assertStringContainsString('eager begin disabled', $captured[0]);
    }

    /**
     * Test that teardown without any persists works fine
     *
     * @return void
     */
    public function testTeardownWithoutPersist(): void
    {
        // Use the lazy variant so setupTest doesn't try to open a real
        // connection (the package's name-based 'test' resolution can be
        // ambiguous in this test app — see other tests in this class).
        $strategy = new LazyFactoryTransactionStrategy();

        $strategy->setupTest([]);
        // No persist calls — teardown should still work
        $strategy->teardownTest();

        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }

    public function testStrategyResetsLeakedPersistDepth(): void
    {
        // DataCompiler::$persistDepth is process-wide. If a prior test
        // threw between startPersistMode() and endPersistMode() (e.g.
        // an exception inside afterBuild), the depth counter would
        // stay incremented and the next test would boot already in
        // persist mode — silently poisoning its association resolution.
        // setupTest() must reset it.
        $factory = CityFactory::new();
        $compiler = $this->dataCompiler($factory);
        $compiler->startPersistMode();
        $compiler->startPersistMode();
        $this->assertTrue($compiler->isInPersistMode(), 'Precondition: depth > 0.');

        $strategy = new LazyFactoryTransactionStrategy();
        $strategy->setupTest([]);

        $this->assertFalse(
            $compiler->isInPersistMode(),
            'setupTest() must reset the leaked persist-depth counter to zero.',
        );

        $strategy->teardownTest();
    }

    public function testTeardownResetsLeakedPersistDepth(): void
    {
        // Symmetric guarantee on the teardown side: a test that throws
        // after startPersistMode() but before endPersistMode() must not
        // leave the counter elevated for the next test in the same process.
        $strategy = new LazyFactoryTransactionStrategy();
        $strategy->setupTest([]);

        $factory = CityFactory::new();
        $compiler = $this->dataCompiler($factory);
        $compiler->startPersistMode();
        $this->assertTrue($compiler->isInPersistMode());

        $strategy->teardownTest();

        $this->assertFalse(
            $compiler->isInPersistMode(),
            'teardownTest() must reset the leaked persist-depth counter to zero.',
        );
    }

    /**
     * Access a factory's private DataCompiler instance for whitebox
     * persist-depth assertions. Reflection-only: the depth counter is
     * not part of the public API.
     */
    private function dataCompiler(BaseFactory $factory): DataCompiler
    {
        $reflection = new ReflectionProperty(BaseFactory::class, 'dataCompiler');

        return $reflection->getValue($factory);
    }

    public function testStrategyResetsSharedDefaultGenerator(): void
    {
        Configure::write('FixtureFactories.generatorType', 'faker');
        BaseFactory::setDefaultGenerator('dummy');

        // Use the lazy variant — the generator-reset check is independent
        // of the eager begin and we don't need a primary-connection
        // transaction for the assertion.
        $strategy = new LazyFactoryTransactionStrategy();
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
    #[AllowMockObjectsWithoutExpectations]
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

    public function testPersistFinalizesNestedAssociatedEntityStateBeforeAfterSaveCallbacks(): void
    {
        $strategy = new FactoryTransactionStrategy();
        $strategy->setupTest([]);

        $captured = [];
        try {
            $article = ArticleFactory::new()
                ->has(
                    AuthorFactory::new()
                        ->afterSave(static function ($author) use (&$captured): void {
                            $captured = [
                                'isNew' => $author->isNew(),
                                'isDirty' => $author->isDirty(),
                                'source' => $author->getSource(),
                            ];
                        }),
                )
                ->save();

            $this->assertSame(
                ['isNew' => false, 'isDirty' => false, 'source' => 'Authors'],
                $captured,
            );
            $this->assertFalse($article->authors[0]->isNew());
            $this->assertFalse($article->authors[0]->isDirty());
            $this->assertSame('Authors', $article->authors[0]->getSource());
        } finally {
            $strategy->teardownTest();
        }
    }

    public function testEnsureTransactionShortCircuitsWhenConnectionAlreadyInTransaction(): void
    {
        // A connection wrapped by an outer test harness reports
        // inTransaction() === true. ensureTransaction must NOT call begin()
        // again — double-begin on the same connection is either silently
        // dropped or rejected by the driver, and either way the strategy's
        // bookkeeping would track a connection it didn't actually begin.
        $connection = $this->createMock(Connection::class);
        $connection->method('configName')->willReturn('outer-wrapped');
        $connection->method('inTransaction')->willReturn(true);
        $connection->expects($this->never())->method('enableSavePoints');
        $connection->expects($this->never())->method('begin');

        $strategy = new FactoryTransactionStrategy();
        $strategy->ensureTransaction($connection);

        // Strategy must not add an already-wrapped connection to its set,
        // otherwise teardown would issue rollback(true) on a transaction
        // the strategy did not begin.
        $reflection = new ReflectionProperty(FactoryTransactionStrategy::class, 'connections');
        $this->assertSame(
            [],
            $reflection->getValue($strategy),
            'ensureTransaction must not track a connection it short-circuited.',
        );
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

    /**
     * Bug fix: if `rollback(true)` throws on connection N (broken socket,
     * killed backend, server-side timeout), the loop must NOT abort —
     * subsequent connections would never be rolled back and `$connections`
     * would never reset, leaking the active instance into the next test.
     * Per-connection isolation + unconditional reset + aggregated rethrow.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testReleaseTransactionsResetsStateEvenIfOneRollbackThrows(): void
    {
        $bad = $this->createMock(Connection::class);
        $bad->method('inTransaction')->willReturn(true);
        $bad->method('rollback')->willThrowException(new RuntimeException('rollback boom'));

        $good = $this->createMock(Connection::class);
        $good->method('inTransaction')->willReturn(true);
        $good->expects($this->once())->method('rollback')->with(true);

        $strategy = new FactoryTransactionStrategy();
        $connections = new ReflectionProperty($strategy, 'connections');
        $connections->setValue($strategy, ['bad' => $bad, 'good' => $good]);

        $caught = null;
        try {
            $strategy->releaseTransactions();
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'A rollback failure must still surface to the caller.');
        $this->assertStringContainsString('bad', $caught->getMessage());
        $this->assertSame(
            [],
            $connections->getValue($strategy),
            'Connections array must reset regardless of rollback exception — otherwise the strategy leaks into the next test.',
        );
    }

    /**
     * Bug fix: `finalizePersistedEntities()` previously short-circuited when
     * the table's *own* connection was not in transaction. For associated
     * entities under a multi-connection cascade the save runs on the
     * **parent's** connection, not the child's — and the child's connection
     * may legitimately not be in transaction. Skipping compensation there left
     * the entity reporting `isNew() === true`, which silently breaks
     * `Table::delete()` and any other identity-based lookup. The compensation
     * is idempotent, so it must run unconditionally.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testFinalizeCompensatesWhenTableConnectionIsNotInTransaction(): void
    {
        $entity = new Entity(['x' => 1]);
        $this->assertTrue($entity->isNew(), 'fresh entity is new before finalize');

        $connection = $this->createMock(Connection::class);
        $connection->method('inTransaction')->willReturn(false);
        $table = $this->createMock(Table::class);
        $table->method('getConnection')->willReturn($connection);
        $table->method('getAlias')->willReturn('SomeAlias');

        $method = new ReflectionMethod(BaseFactory::class, 'finalizePersistedEntities');
        $method->invoke(AuthorFactory::new(), [$entity], $table);

        $this->assertFalse(
            $entity->isNew(),
            'Compensation must run regardless of the table connection state.',
        );
        $this->assertSame('SomeAlias', $entity->getSource());
    }
}
