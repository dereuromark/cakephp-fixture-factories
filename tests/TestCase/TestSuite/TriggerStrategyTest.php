<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\TestSuite\Sniffer\BaseTriggerBasedTableSniffer;
use CakephpFixtureFactories\TestSuite\Sniffer\SnifferRegistry;
use CakephpFixtureFactories\TestSuite\TriggerStrategy;
use CakephpFixtureFactories\TestSuite\TruncateDirtyTables;

class TriggerStrategyTest extends TestCase
{
    use TruncateDirtyTables;

    protected function tearDown(): void
    {
        Configure::delete(TriggerStrategy::TEST_SUITE_LIGHT_IGNORED_CONNECTIONS_CONFIG_KEY);

        parent::tearDown();
    }

    public function testInsertMarksTableDirty(): void
    {
        CountryFactory::new()->save();

        $this->assertContains('countries', SnifferRegistry::get('test')->getDirtyTables());
    }

    public function testDirtyTableIsTruncatedOnNextSetup(): void
    {
        CountryFactory::new()->save();

        (new TriggerStrategy())->setupTest([]);

        $this->assertSame(0, CountryFactory::table()->find()->count());
    }

    public function testTeardownKeepsData(): void
    {
        CountryFactory::new()->save();

        (new TriggerStrategy())->teardownTest();

        $this->assertSame(1, CountryFactory::table()->find()->count());
    }

    public function testMigrationTablesGetNoTrigger(): void
    {
        $sniffer = SnifferRegistry::get('test');
        $this->assertInstanceOf(BaseTriggerBasedTableSniffer::class, $sniffer);

        $this->assertNotContains('cake_migrations', $sniffer->getAllTablesExceptPhinxlogs(true));
        $this->assertNotContains('cake_seeds', $sniffer->getAllTablesExceptPhinxlogs());
        $this->assertNotContains(BaseTriggerBasedTableSniffer::TRIGGER_PREFIX . 'cake_migrations', $sniffer->getTriggers());
    }

    public function testIgnoredConnectionIsSkipped(): void
    {
        Configure::write(TriggerStrategy::TEST_SUITE_LIGHT_IGNORED_CONNECTIONS_CONFIG_KEY, ['test_dummy']);

        $strategy = new TriggerStrategy();

        $this->assertTrue($strategy->isConnectionTruncationSkipped('test_dummy'));
        $this->assertFalse($strategy->isConnectionTruncationSkipped('test'));
    }
}
