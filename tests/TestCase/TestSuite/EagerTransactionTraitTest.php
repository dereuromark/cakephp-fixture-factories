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
 * EagerTransactionTrait gives a single test class eager transactional
 * semantics on top of the default lazy strategy — the rest of the suite
 * stays lazy.
 */
class EagerTransactionTraitTest extends TestCase
{
    use EagerTransactionTrait;
    use TruncateDirtyTables;

    /**
     * Lazy strategy is installed manually here (the package's test
     * suite does not run with a global strategy in place; production
     * users would have it set via 'TestSuite.fixtureStrategy' in
     * config/app.php).
     */
    protected function setUp(): void
    {
        parent::setUp();
        FactoryTableTracker::getInstance()->clear();

        $strategy = new FactoryTransactionStrategy();
        $strategy->setupTest([]);
        // ensureEagerTransactionForTest() — the trait's #[Before] hook —
        // runs after this and uses the active instance set above.
    }

    protected function tearDown(): void
    {
        $strategy = FactoryTransactionStrategy::getActiveInstance();
        if ($strategy !== null) {
            $strategy->teardownTest();
        }
        FactoryTableTracker::getInstance()->clear();
        BaseFactory::resetDefaultGenerator();
        parent::tearDown();
    }

    /**
     * The trait's #[Before] primes a transaction on the eager connection,
     * even though no Factory has persisted yet. A direct $table->save()
     * here would therefore be rolled back.
     *
     * @return void
     */
    public function testTraitPrimesTransactionBeforeTestBody(): void
    {
        $connection = ConnectionManager::get('test');

        $this->assertTrue(
            $connection->inTransaction(),
            'EagerTransactionTrait should have primed a transaction via #[Before]',
        );
    }

    /**
     * Sanity: the ensure call is idempotent — a Factory save mid-test
     * doesn't try to begin a second transaction on the same connection.
     *
     * @return void
     */
    public function testFactorySaveIsCompatibleWithEagerPriming(): void
    {
        $city = CityFactory::new(['name' => 'Trait City'])->save();

        $this->assertNotEmpty($city->id);
    }
}
