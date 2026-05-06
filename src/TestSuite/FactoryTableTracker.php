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
     * Tables grouped by connection. Multiple connections can hold a table with
     * the same SQL name (e.g. read/write split, multi-tenant), so the storage
     * is nested rather than a flat name-to-connection map.
     *
     * @var array<string, array<string, true>> Connection name => set of table names.
     */
    private array $tablesByConnection = [];

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

        $this->tablesByConnection[$connectionName][$tableName] = true;
    }

    /**
     * Get all tracked tables flattened to a name-to-connection map.
     *
     * Note: when the same SQL table name is used on multiple connections, only
     * one connection wins in this view — use {@see getTablesByConnection()}
     * for an unambiguous picture.
     *
     * @return array<string, string> Table name => connection name mapping.
     */
    public function getTables(): array
    {
        $flat = [];
        foreach ($this->tablesByConnection as $connection => $tables) {
            foreach (array_keys($tables) as $name) {
                $flat[$name] = $connection;
            }
        }

        return $flat;
    }

    /**
     * Get tracked table names only (deduplicated across connections).
     *
     * @return array<string>
     */
    public function getTableNames(): array
    {
        $names = [];
        foreach ($this->tablesByConnection as $tables) {
            foreach (array_keys($tables) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Get tables grouped by connection
     *
     * @return array<string, array<string>> Connection name => table names
     */
    public function getTablesByConnection(): array
    {
        $grouped = [];
        foreach ($this->tablesByConnection as $connection => $tables) {
            $grouped[$connection] = array_keys($tables);
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
        $this->tablesByConnection = [];
    }

    /**
     * Check if any tables have been tracked
     *
     * @return bool
     */
    public function hasTables(): bool
    {
        return $this->tablesByConnection !== [];
    }
}
