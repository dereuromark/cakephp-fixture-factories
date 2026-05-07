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

        // Align $eagerConnection to whatever connection name the
        // factory's table actually uses in this test environment, so
        // the trait wraps the same connection the test inspects.
        $this->eagerConnection = CityFactory::new()->getTable()->getConnection()->configName();
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
        $resolved = \Cake\Datasource\ConnectionManager::get($this->eagerConnection);

        // Diagnostic: verify the trait resolves the same Connection
        // instance the factory uses. If these are different instances,
        // the trait's begin() lands on a different connection than the
        // assertion below inspects. Spell that out so a CI failure
        // points at the connection-resolution issue immediately.
        $this->assertSame(
            $connection,
            $resolved,
            sprintf(
                'Trait connection differs from factory connection. '
                . 'eagerConnection=%s, factoryConfigName=%s, resolvedConfigName=%s',
                $this->eagerConnection,
                $connection->configName(),
                $resolved->configName(),
            ),
        );

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

        // Should not throw — the trait method short-circuits when
        // getActiveInstance() returns null. We re-assert null after to
        // confirm the call path didn't accidentally install one.
        $this->ensureEagerTransactionForTest();
        $this->assertNull(FactoryTransactionStrategy::getActiveInstance());
    }
}
