<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\TestSuite\Sniffer\BaseTriggerBasedTableSniffer;
use CakephpFixtureFactories\TestSuite\Sniffer\SnifferRegistry;
use CakephpFixtureFactories\TestSuite\Sniffer\SqliteTriggerBasedTableSniffer;
use CakephpFixtureFactories\TestSuite\TriggerStrategy;
use CakephpFixtureFactories\TestSuite\TruncateDirtyTables;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * @return array<string, array<bool>>
     */
    public static function legacyCollectorProvider(): array
    {
        return [
            'legacy only' => [false],
            'legacy and new collector' => [true],
        ];
    }

    #[DataProvider('legacyCollectorProvider')]
    public function testLegacyCollectorIsMigrated(bool $newCollectorExists): void
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');
        $this->skipIf(!$connection->getDriver() instanceof Sqlite, 'The legacy setup below is written in SQLite syntax.');
        SnifferRegistry::clear();
        if ($newCollectorExists) {
            (new SqliteTriggerBasedTableSniffer($connection))->createDirtyTableCollector();
        }
        $connection->execute('CREATE TABLE test_suite_light_dirty_tables (table_name VARCHAR(128) PRIMARY KEY)');
        $connection->execute("CREATE TRIGGER dts_countries AFTER INSERT ON countries BEGIN INSERT OR IGNORE INTO test_suite_light_dirty_tables VALUES ('countries'); END");
        try {
            (new TriggerStrategy())->setupTest([]);
            $sniffer = SnifferRegistry::get('test');
            $this->assertNotContains(BaseTriggerBasedTableSniffer::LEGACY_DIRTY_TABLE_COLLECTOR, $sniffer->getAllTables(true));
            $this->assertContains(BaseTriggerBasedTableSniffer::DIRTY_TABLE_COLLECTOR, $sniffer->getAllTables());
            CountryFactory::new()->save();
            $this->assertContains('countries', $sniffer->getDirtyTables());
        } finally {
            SnifferRegistry::clear();
        }
    }
}
