<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\Database\Connection;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use CakephpFixtureFactories\TestSuite\LazyTransactionTrait;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

/**
 * Coverage for {@see LazyTransactionTrait}: opting a single test
 * class out of the eager primary-connection wrap that the default
 * {@see FactoryTransactionStrategy} performs in setupTest().
 *
 * The trait wires its release call to PHPUnit's #[Before] hook so it
 * fires before each test in the using class. We test the underlying
 * `releaseTransactions()` flow directly here so the assertions don't
 * depend on PHPUnit's attribute dispatch order.
 */
class LazyTransactionTraitTest extends TestCase
{
    use LazyTransactionTrait;
    use TruncateDirtyTables;

    private ?FactoryTransactionStrategy $strategy = null;

    protected function setUp(): void
    {
        parent::setUp();
        FactoryTableTracker::getInstance()->clear();

        $connection = CityFactory::new()->getTable()->getConnection();
        // Mimic the production wiring where 'TestSuite.fixtureStrategy'
        // installs the (eager-by-default) strategy. We override the
        // primary-connection acquisition to use the exact Connection
        // CityFactory's table reports — the package's bootstrap
        // aliases connection names ambiguously, so a name-based lookup
        // can land on a sibling instance.
        $this->strategy = new class ($connection) extends FactoryTransactionStrategy {
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
        $this->strategy->setupTest([]);
    }

    protected function tearDown(): void
    {
        $this->strategy?->teardownTest();
        $this->strategy = null;
        FactoryTableTracker::getInstance()->clear();
        BaseFactory::resetDefaultGenerator();
        parent::tearDown();
    }

    /**
     * Calling releaseEagerTransactionForTest() — the method PHPUnit
     * normally fires from the trait's #[Before] hook — rolls back the
     * eager-begun transaction. Subsequent direct table operations
     * inside the test would NOT be covered by the rollback.
     *
     * @return void
     */
    public function testReleaseEagerTransactionRollsBackPrimaryConnection(): void
    {
        $connection = CityFactory::new()->getTable()->getConnection();

        $this->assertTrue(
            $connection->inTransaction(),
            'Sanity check: eager strategy should have primed a transaction in setUp',
        );

        $this->releaseEagerTransactionForTest();

        $this->assertFalse(
            $connection->inTransaction(),
            'releaseEagerTransactionForTest should have rolled back the primary transaction',
        );
    }

    /**
     * After release, a Factory persist inside the test lazily begins a
     * fresh transaction (on whatever connection it writes to) — same
     * contract as the {@see LazyFactoryTransactionStrategy}.
     *
     * @return void
     */
    public function testFactorySaveLazyBeginsAfterRelease(): void
    {
        $connection = CityFactory::new()->getTable()->getConnection();

        $this->releaseEagerTransactionForTest();
        $this->assertFalse($connection->inTransaction());

        $city = CityFactory::new(['name' => 'After Release'])->save();
        $this->assertNotEmpty($city->id);

        $this->assertTrue(
            $connection->inTransaction(),
            'Factory persist should lazily begin a fresh transaction',
        );
    }

    /**
     * Release without an active strategy is a silent no-op rather than
     * a throw — matches the documented contract that the trait can be
     * safely composed alongside test cases that skip the global
     * fixture strategy.
     *
     * @return void
     */
    public function testNoopWithoutActiveStrategy(): void
    {
        $this->strategy?->teardownTest();
        $this->strategy = null;

        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());

        // Should not throw.
        $this->releaseEagerTransactionForTest();
        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }
}
