<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

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
 * expectation that the active strategy instance has been set.
 */
class EagerTransactionTraitTest extends TestCase
{
    use EagerTransactionTrait;
    use TruncateDirtyTables;

    private ?FactoryTransactionStrategy $strategy = null;

    /**
     * Override the trait's connection resolution to use the exact
     * Connection instance CityFactory's table reports. This bypasses
     * any alias-map ambiguity in the test app's bootstrap (where
     * `ConnectionManager::get('test')` may resolve through the alias
     * map to a sibling-configured connection).
     *
     * @return list<\Cake\Database\Connection>
     */
    protected function getEagerConnections(): array
    {
        return [CityFactory::new()->getTable()->getConnection()];
    }

    protected function setUp(): void
    {
        parent::setUp();
        FactoryTableTracker::getInstance()->clear();

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
     * transaction on each connection returned by
     * getEagerConnections().
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

        // Should not throw — the trait method short-circuits when
        // getActiveInstance() returns null. We re-assert null after to
        // confirm the call path didn't accidentally install one.
        $this->ensureEagerTransactionForTest();
        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }
}
