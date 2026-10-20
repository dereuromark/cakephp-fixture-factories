<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\TestSuite\Sniffer\BaseTriggerBasedTableSniffer;
use CakephpFixtureFactories\TestSuite\Sniffer\MysqlTriggerBasedTableSniffer;
use CakephpFixtureFactories\TestSuite\Sniffer\PostgresTriggerBasedTableSniffer;
use CakephpFixtureFactories\TestSuite\Sniffer\SqliteTriggerBasedTableSniffer;
use CakephpFixtureFactories\TestSuite\TriggerStrategyScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class TriggerStrategyScopeTest extends TestCase
{
    /**
     * @var array<string>
     */
    private array $temporaryConnections = [];

    protected function tearDown(): void
    {
        Configure::delete(TriggerStrategyScope::CONFIG_KEY);
        Configure::delete(TriggerStrategyScope::LEGACY_IGNORED_CONNECTIONS_KEY);
        foreach ($this->temporaryConnections as $name) {
            ConnectionManager::drop($name);
        }
        parent::tearDown();
    }

    private function connection(string $name, array $options = []): void
    {
        ConnectionManager::setConfig($name, ['className' => Connection::class, 'driver' => Sqlite::class, 'database' => ':memory:'] + $options);
        $this->temporaryConnections[] = $name;
    }

    #[DataProvider('connectionProvider')]
    public function testConnectionScope(string $name, bool $included): void
    {
        if ($name !== 'test') {
            $this->connection($name);
        }
        $scope = new TriggerStrategyScope();
        $this->assertSame($included, $scope->isConnectionIncluded($name));
        $this->assertSame($included ? [$name] : [], $scope->filterConnections([$name]));
    }

    public static function connectionProvider(): array
    {
        return [
            ['test', true],
            ['test_scope_example', true],
            ['scope_example', false],
            ['test_debug_kit', false],
        ];
    }

    public function testIgnoredConnectionsUnionAndLegacySkip(): void
    {
        $this->connection('test_scope_new');
        $this->connection('test_scope_old');
        $this->connection('test_scope_skip', [TriggerStrategyScope::LEGACY_SKIP_CONNECTION_KEY => true]);
        Configure::write(TriggerStrategyScope::CONFIG_KEY . '.' . TriggerStrategyScope::IGNORED_CONNECTIONS_KEY, ['test_scope_new']);
        Configure::write(TriggerStrategyScope::LEGACY_IGNORED_CONNECTIONS_KEY, ['test_scope_old']);
        $scope = new TriggerStrategyScope();
        $this->assertSame([], $scope->filterConnections(['test_scope_new', 'test_scope_old', 'test_scope_skip']));
    }

    public function testTableScope(): void
    {
        $scope = new TriggerStrategyScope();
        $tables = ['users', 'phinxlog', 'my_phinxlog_archive', 'cake_migrations', 'cake_seeds', BaseTriggerBasedTableSniffer::DIRTY_TABLE_COLLECTOR, BaseTriggerBasedTableSniffer::LEGACY_DIRTY_TABLE_COLLECTOR];
        $this->assertSame(['users'], $scope->filterTables($tables));
        $this->assertFalse($scope->isTableIncluded('cake_seeds'));
        $this->assertTrue($scope->isTableIncluded('users'));
        $this->assertSame(['users', BaseTriggerBasedTableSniffer::DIRTY_TABLE_COLLECTOR], $scope->filterMigrationTables(['users', 'cake_seeds', BaseTriggerBasedTableSniffer::DIRTY_TABLE_COLLECTOR]));
    }

    public function testCollectorModePrecedence(): void
    {
        $this->connection('test_scope_mode', [TriggerStrategyScope::LEGACY_COLLECTOR_MODE_KEY => 'TEMP']);
        $scope = new TriggerStrategyScope();
        $this->assertSame('PERM', $scope->collectorMode('test'));
        $this->assertSame('TEMP', $scope->collectorMode('test_scope_mode'));
        Configure::write(TriggerStrategyScope::CONFIG_KEY . '.' . TriggerStrategyScope::COLLECTOR_MODE_KEY, 'PERM');
        $this->assertSame('PERM', $scope->collectorMode('test_scope_mode'));
        Configure::write(TriggerStrategyScope::CONFIG_KEY . '.' . TriggerStrategyScope::COLLECTOR_MODE_KEY, ['test_scope_mode' => 'TEMP']);
        $this->assertSame('TEMP', $scope->collectorMode('test_scope_mode'));
    }

    public function testInvalidCollectorMode(): void
    {
        Configure::write(TriggerStrategyScope::CONFIG_KEY . '.' . TriggerStrategyScope::COLLECTOR_MODE_KEY, ['test' => 'BAD']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'BAD' for connection test");
        (new TriggerStrategyScope())->collectorMode('test');
    }

    public function testTableSnifferPrecedence(): void
    {
        $this->connection('test_scope_sniffer', [TriggerStrategyScope::LEGACY_TABLE_SNIFFER_KEY => SqliteTriggerBasedTableSniffer::class]);
        $scope = new TriggerStrategyScope();
        $this->assertNull($scope->tableSniffer('test'));
        $this->assertSame(SqliteTriggerBasedTableSniffer::class, $scope->tableSniffer('test_scope_sniffer'));
        Configure::write(TriggerStrategyScope::CONFIG_KEY . '.' . TriggerStrategyScope::TABLE_SNIFFER_KEY, MysqlTriggerBasedTableSniffer::class);
        $this->assertSame(MysqlTriggerBasedTableSniffer::class, $scope->tableSniffer('test_scope_sniffer'));
        Configure::write(TriggerStrategyScope::CONFIG_KEY . '.' . TriggerStrategyScope::TABLE_SNIFFER_KEY, ['test_scope_sniffer' => PostgresTriggerBasedTableSniffer::class]);
        $this->assertSame(PostgresTriggerBasedTableSniffer::class, $scope->tableSniffer('test_scope_sniffer'));
    }
}
