<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\TestSuite;

use Cake\ORM\Table;

/**
 * Tracks which tables are being written to by fixture factories
 *
 * This singleton class maintains a registry of all tables that have been
 * modified by fixture factories during test execution. This information can
 * be used by transaction strategies to automatically manage database state.
 */
class FactoryTableTracker
{
    /**
     * Singleton instance
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Map of table names to connections
     *
     * @var array<string, string> Table name => connection name
     */
    private array $tables = [];

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct()
    {
    }

    /**
     * Get the singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Track a table that has been written to
     *
     * @param \Cake\ORM\Table $table The table being written to
     *
     * @return void
     */
    public function trackTable(Table $table): void
    {
        $tableName = $table->getTable();
        $connectionName = $table->getConnection()->configName();

        $this->tables[$tableName] = $connectionName;
    }

    /**
     * Get all tracked tables
     *
     * @return array<string, string> Table name => connection name mapping
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Get tracked table names only
     *
     * @return array<string>
     */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Get tables grouped by connection
     *
     * @return array<string, array<string>> Connection name => table names
     */
    public function getTablesByConnection(): array
    {
        $grouped = [];
        foreach ($this->tables as $table => $connection) {
            if (!isset($grouped[$connection])) {
                $grouped[$connection] = [];
            }
            $grouped[$connection][] = $table;
        }

        return $grouped;
    }

    /**
     * Clear all tracked tables
     *
     * @return void
     */
    public function clear(): void
    {
        $this->tables = [];
    }

    /**
     * Check if any tables have been tracked
     *
     * @return bool
     */
    public function hasTables(): bool
    {
        return !empty($this->tables);
    }
}
