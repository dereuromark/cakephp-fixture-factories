<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://github.com/vierge-noire/cakephp-test-suite-light
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\TestSuite\Sniffer;

use Cake\Datasource\ConnectionInterface;
use CakephpFixtureFactories\TestSuite\TriggerStrategyScope;
use RuntimeException;
use Throwable;

abstract class BaseTriggerBasedTableSniffer extends BaseTableSniffer
{
    /**
     * The name of the table collecting dirty tables
     *
     * @var string
     */
    public const DIRTY_TABLE_COLLECTOR = 'fixture_factories_dirty_tables';

    /**
     * @var string
     */
    public const LEGACY_DIRTY_TABLE_COLLECTOR = 'test_suite_light_dirty_tables';

    /**
     * @var string
     */
    public const TRIGGER_PREFIX = 'dts_';

    /**
     * @var string
     */
    public const MODE_KEY = 'dirtyTableCollectorMode';

    /**
     * The dirty table collector is a permanent table
     *
     * @var string
     */
    public const PERM_MODE = 'PERM';

    /**
     * The dirty table collector is a temporary table
     *
     * @var string
     */
    public const TEMP_MODE = 'TEMP';

    /**
     * @var string
     */
    protected $mode;

    /**
     * Get triggers relative to the database dirty table collector
     *
     * @return array<string>
     */
    abstract public function getTriggers(): array;

    /**
     * Drop triggers relative to the database dirty table collector
     *
     * @return void
     */
    abstract public function dropTriggers();

    /**
     * Create triggers on all tables listening to inserts
     *
     * @return void
     */
    abstract public function createTriggers(): void;

    /**
     * Mark all tables except phinxlogs and dirty table collector as dirty.
     *
     * @return void
     */
    abstract public function markAllTablesAsDirty(): void;

    /**
     * Create the procedure truncating the dirty tables.
     *
     * @return void
     */
    abstract public function createTruncateDirtyTablesProcedure(): void;

    /**
     * @param \Cake\Datasource\ConnectionInterface $connection
     * @param \CakephpFixtureFactories\TestSuite\TriggerStrategyScope|null $scope
     */
    public function __construct(ConnectionInterface $connection, ?TriggerStrategyScope $scope = null)
    {
        parent::__construct($connection, $scope);
        $this->mode = $this->getDefaultMode($connection);
    }

    /**
     * Check that the dirty table collector exists
     *
     * @return bool
     */
    public function dirtyTableCollectorExists(): bool
    {
        return in_array(self::DIRTY_TABLE_COLLECTOR, $this->getAllTables(true));
    }

    /**
     * Starts the sniffer if the dirty table collector is missing: creates the collector,
     * the triggers and the truncate procedure, then marks all tables as dirty.
     *
     * @return void
     */
    public function init(): void
    {
        if (!$this->dirtyTableCollectorExists()) {
            $this->createDirtyTableCollector();
            try {
                $this->createTriggers();
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $message .= PHP_EOL . 'Please truncate your test schema manually and run the test suite again.';

                throw new RuntimeException($message);
            }
            $this->createTruncateDirtyTablesProcedure();
            $this->markAllTablesAsDirty();
        }
    }

    /**
     * Drops the triggers and collector a test-suite-light setup left behind, so init() reinstalls them.
     *
     * @return void
     */
    public function migrateLegacyCollector(): void
    {
        if (!in_array(self::LEGACY_DIRTY_TABLE_COLLECTOR, $this->getAllTables(true), true)) {
            return;
        }

        $this->dropTriggers();
        // Also drop a new collector, otherwise init() would skip reinstalling the triggers.
        $this->dropDirtyTableCollector();
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getConnection();
        $connection->execute('DROP TABLE IF EXISTS ' . self::LEGACY_DIRTY_TABLE_COLLECTOR);
    }

    /**
     * Get the name of the dirty table locator.
     *
     * @return string
     */
    public function collectorName(): string
    {
        return self::DIRTY_TABLE_COLLECTOR;
    }

    /**
     * The length of the trigger name is limited to 64 do to MySQL constrain.
     *
     * @param string $tableName Name of the table to create a trigger on.
     *
     * @return string
     */
    protected function getTriggerName(string $tableName): string
    {
        return substr(self::TRIGGER_PREFIX . $tableName, 0, 64);
    }

    /**
     * Find all tables where an insert happened
     * This also includes empty tables, where a delete
     * was performed after an insert
     *
     * @return array<string>
     */
    public function getDirtyTables(): array
    {
        try {
            return $this->fetchQuery('SELECT table_name FROM ' . $this->collectorName());
        } catch (Throwable $e) {
            // Retry once: init() only repairs a missing collector, any other failure must surface.
            $this->init();

            return $this->fetchQuery('SELECT table_name FROM ' . $this->collectorName());
        }
    }

    /**
     * Fetch all tables, excluded from Phinx related and the dirty table collector.
     *
     * @deprecated Use getScopedTables() instead.
     *
     * @param bool $forceFetch
     *
     * @return array<string>
     */
    public function getAllTablesExceptPhinxlogsAndCollector(bool $forceFetch = false): array
    {
        return $this->getScopedTables($forceFetch);
    }

    /**
     * Create the table gathering the dirty tables
     *
     * @return void
     */
    public function createDirtyTableCollector(): void
    {
        $temporary = $this->isInTemporaryMode() ? 'TEMPORARY' : '';
        $dirtyTable = self::DIRTY_TABLE_COLLECTOR;

        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getConnection();
        $connection->execute("
            CREATE {$temporary} TABLE IF NOT EXISTS {$dirtyTable} (
                table_name VARCHAR(128) PRIMARY KEY
            );
        ");
    }

    /**
     * Drop the table gathering the dirty tables
     *
     * @return void
     */
    public function dropDirtyTableCollector()
    {
        $dirtyTable = self::DIRTY_TABLE_COLLECTOR;
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getConnection();
        $connection->execute("DROP TABLE IF EXISTS {$dirtyTable}");
    }

    /**
     * Keep the dirty table collector in a permanent table.
     *
     * @return void
     */
    public function activatePermanentMode(): void
    {
        $this->setMode(self::PERM_MODE);
    }

    /**
     * Keep the dirty table collector in a temporary table.
     *
     * @return void
     */
    public function activateTemporaryMode(): void
    {
        $this->setMode(self::TEMP_MODE);
    }

    /**
     * @deprecated Use activatePermanentMode() instead.
     *
     * @return void
     */
    public function activateMainMode(): void
    {
        $this->activatePermanentMode();
    }

    /**
     * @deprecated Use activateTemporaryMode() instead.
     *
     * @return void
     */
    public function activateTempMode(): void
    {
        $this->activateTemporaryMode();
    }

    /**
     * @param string $mode
     *
     * @return void
     */
    public function setMode(string $mode): void
    {
        if ($this->mode === $mode) {
            return;
        }
        $this->mode = $mode;
        $this->restart();
    }

    /**
     * Get the mode on which the sniffer is running
     * This defines if the collector table is
     * temporary or not.
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Returns the configured collector mode, PERM when none is set.
     *
     * @param \Cake\Datasource\ConnectionInterface $connection
     *
     * @return string
     */
    public function getDefaultMode(ConnectionInterface $connection): string
    {
        return $this->scope->collectorMode($connection->configName());
    }

    /**
     * @return bool
     */
    public function isInTemporaryMode(): bool
    {
        return $this->getMode() === self::TEMP_MODE;
    }

    /**
     * @return bool
     */
    public function isInPermanentMode(): bool
    {
        return $this->getMode() === self::PERM_MODE;
    }

    /**
     * @deprecated Use isInTemporaryMode() instead.
     *
     * @return bool
     */
    public function isInTempMode(): bool
    {
        return $this->isInTemporaryMode();
    }

    /**
     * @deprecated Use isInPermanentMode() instead.
     *
     * @return bool
     */
    public function isInMainMode(): bool
    {
        return $this->isInPermanentMode();
    }
}
