<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\TestSuite\EagerTransactionTrait;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

/**
 * Coverage for the trait's ensureEagerTransactionForTest() method.
 *
 * The trait wires this method to PHPUnit's #[Before] hook so it fires
 * before each test in the using class. We test the method directly
 * here so the assertions don't depend on PHPUnit's attribute
 * dispatch — they exercise the trait's own logic given the documented
 * expectation about the active strategy instance being set.
 */
class EagerTransactionTraitTest extends TestCase
{
    use EagerTransactionTrait;
    use TruncateDirtyTables;

    private ?FactoryTransactionStrategy $strategy = null;

    protected function setUp(): void
    {
        parent::setUp();
        FactoryTableTracker::getInstance()->clear();

        // Mimic the production wiring where 'TestSuite.fixtureStrategy'
        // installs the lazy strategy; we install it manually here since
        // the package's own test suite does not configure a global
        // strategy.
        $this->strategy = new FactoryTransactionStrategy();
        $this->strategy->setupTest([]);
    }

    protected function tearDown(): void
    {
        if ($this->strategy !== null) {
            $this->strategy->teardownTest();
            $this->strategy = null;
        }
        FactoryTableTracker::getInstance()->clear();
        BaseFactory::resetDefaultGenerator();
        parent::tearDown();
    }

    /**
     * Calling ensureEagerTransactionForTest() (the method PHPUnit
     * normally fires from the trait's #[Before] hook) opens a
     * transaction on the configured eager connection.
     *
     * @return void
     */
    public function testEnsureEagerTransactionForTestPrimesTheConnection(): void
    {
        $connection = CityFactory::new()->getTable()->getConnection();
        $this->assertFalse(
            $connection->inTransaction(),
            'Lazy strategy should not have begun a transaction yet',
        );

        $this->ensureEagerTransactionForTest();

        $this->assertTrue(
            $connection->inTransaction(),
            'Trait method should have primed a transaction on the eager connection',
        );
    }

    /**
     * The eager prime is idempotent and compatible with subsequent
     * Factory persists. ensureTransaction() short-circuits when the
     * connection is already enrolled, so a Factory save() that follows
     * the trait's prime works without re-issuing BEGIN.
     *
     * @return void
     */
    public function testFactorySaveIsCompatibleWithEagerPriming(): void
    {
        $this->ensureEagerTransactionForTest();

        $city = CityFactory::new(['name' => 'Trait City'])->save();
        $this->assertNotEmpty($city->id);
    }

    /**
     * Setting $eagerConnection to '' on the using class disables the
     * prime — useful for downstream subclasses that want to opt out.
     *
     * @return void
     */
    public function testEmptyEagerConnectionSkipsPriming(): void
    {
        $connection = CityFactory::new()->getTable()->getConnection();
        $previous = $this->eagerConnection;
        $this->eagerConnection = '';

        try {
            $this->ensureEagerTransactionForTest();

            $this->assertFalse(
                $connection->inTransaction(),
                'Empty eagerConnection should skip the prime',
            );
        } finally {
            $this->eagerConnection = $previous;
        }
    }

    /**
     * Without an active strategy, the trait method silently no-ops
     * rather than throwing — matches the documented contract that
     * the trait can be safely composed alongside test cases that
     * skip the global fixture strategy.
     *
     * @return void
     */
    public function testNoopWithoutActiveStrategy(): void
    {
        $this->strategy?->teardownTest();
        $this->strategy = null;

        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());

        // Should not throw.
        $this->ensureEagerTransactionForTest();

        $this->expectNotToPerformAssertions();
    }

    /**
     * Re-installs the strategy so tearDown() finds an active instance
     * to teardown after the no-op test above. PHPUnit shares the
     * test-method order non-determinism risk; this is defensive.
     *
     * @return void
     */
    protected function assertPostConditions(): void
    {
        if ($this->strategy === null) {
            $this->strategy = new FactoryTransactionStrategy();
            $this->strategy->setupTest([]);
        }
        parent::assertPostConditions();
    }
}
