<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use CakephpFixtureFactories\TestSuite\Sniffer\BaseTriggerBasedTableSniffer;
use InvalidArgumentException;

/**
 * Selects the connections and tables managed by TriggerStrategy.
 */
class TriggerStrategyScope
{
    /**
     * @var string
     */
    public const CONFIG_KEY = 'FixtureFactories';

    /**
     * @var string
     */
    public const IGNORED_CONNECTIONS_KEY = 'ignoredConnections';

    /**
     * @var string
     */
    public const COLLECTOR_MODE_KEY = 'collectorMode';

    /**
     * @var string
     */
    public const TABLE_SNIFFER_KEY = 'tableSniffer';

    /**
     * @var string
     */
    public const LEGACY_IGNORED_CONNECTIONS_KEY = 'TestSuiteLightIgnoredConnections';

    /**
     * @var string
     */
    public const LEGACY_SKIP_CONNECTION_KEY = 'skipInTestSuiteLight';

    /**
     * @var string
     */
    public const LEGACY_COLLECTOR_MODE_KEY = 'dirtyTableCollectorMode';

    /**
     * @var string
     */
    public const LEGACY_TABLE_SNIFFER_KEY = 'tableSniffer';

    /**
     * @var array<string>
     */
    public const MIGRATION_TABLES = ['cake_migrations', 'cake_seeds'];

    /**
     * @param array<string> $connectionNames
     *
     * @return array<string>
     */
    public function filterConnections(array $connectionNames): array
    {
        return array_values(array_filter($connectionNames, $this->isConnectionIncluded(...)));
    }

    public function isConnectionIncluded(string $connectionName): bool
    {
        if ($connectionName !== 'test' && !str_starts_with($connectionName, 'test_')) {
            return false;
        }
        if ($connectionName === 'test_debug_kit') {
            return false;
        }

        $ignored = array_merge(
            (array)Configure::read(self::CONFIG_KEY . '.' . self::IGNORED_CONNECTIONS_KEY, []),
            (array)Configure::read(self::LEGACY_IGNORED_CONNECTIONS_KEY, []),
        );
        if (in_array($connectionName, $ignored, true)) {
            return false;
        }

        return (ConnectionManager::getConfig($connectionName)[self::LEGACY_SKIP_CONNECTION_KEY] ?? false) !== true;
    }

    /**
     * @param array<string> $tables
     *
     * @return array<string>
     */
    public function filterTables(array $tables): array
    {
        return array_values(array_filter($tables, $this->isTableIncluded(...)));
    }

    public function isTableIncluded(string $table): bool
    {
        return !$this->isMigrationTable($table)
            && $table !== BaseTriggerBasedTableSniffer::DIRTY_TABLE_COLLECTOR
            && $table !== BaseTriggerBasedTableSniffer::LEGACY_DIRTY_TABLE_COLLECTOR;
    }

    /**
     * @param array<string> $tables
     *
     * @return array<string>
     */
    public function filterMigrationTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table): bool => !$this->isMigrationTable($table)));
    }

    private function isMigrationTable(string $table): bool
    {
        return str_contains($table, 'phinxlog') || in_array($table, self::MIGRATION_TABLES, true);
    }

    public function collectorMode(string $connectionName): string
    {
        $mode = $this->connectionOption(
            $connectionName,
            self::COLLECTOR_MODE_KEY,
            self::LEGACY_COLLECTOR_MODE_KEY,
            BaseTriggerBasedTableSniffer::PERM_MODE,
        );
        if (!in_array($mode, [BaseTriggerBasedTableSniffer::PERM_MODE, BaseTriggerBasedTableSniffer::TEMP_MODE], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid collector mode %s for connection %s',
                var_export($mode, true),
                $connectionName,
            ));
        }

        return $mode;
    }

    /**
     * @throws \InvalidArgumentException
     *
     * @return class-string|null
     */
    public function tableSniffer(string $connectionName): ?string
    {
        $sniffer = $this->connectionOption($connectionName, self::TABLE_SNIFFER_KEY, self::LEGACY_TABLE_SNIFFER_KEY, null);
        if ($sniffer !== null && (!is_string($sniffer) || !class_exists($sniffer))) {
            throw new InvalidArgumentException(sprintf('Invalid table sniffer %s for connection %s', var_export($sniffer, true), $connectionName));
        }

        return $sniffer;
    }

    private function connectionOption(string $connectionName, string $key, string $legacyKey, ?string $default): mixed
    {
        $configured = Configure::read(self::CONFIG_KEY . '.' . $key);
        if (is_array($configured) && array_key_exists($connectionName, $configured)) {
            return $configured[$connectionName];
        }
        if (is_string($configured)) {
            return $configured;
        }

        return ConnectionManager::getConfig($connectionName)[$legacyKey] ?? $default;
    }
}
