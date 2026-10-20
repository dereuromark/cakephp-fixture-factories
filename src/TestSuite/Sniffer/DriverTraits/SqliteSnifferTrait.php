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

namespace CakephpFixtureFactories\TestSuite\Sniffer\DriverTraits;

use Cake\Database\Connection;
use CakephpFixtureFactories\TestSuite\Sniffer\BaseTriggerBasedTableSniffer;

/**
 * Trait SqliteSnifferTrait
 *
 * @package CakephpFixtureFactories\TestSuite\Sniffer\DriverTraits
 */
trait SqliteSnifferTrait
{
    /**
     * @return array<string>
     */
    public function getTriggers(): array
    {
        $triggerPrefix = BaseTriggerBasedTableSniffer::TRIGGER_PREFIX;

        $triggers = $this->fetchQuery("
            SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE '{$triggerPrefix}%'
            UNION
            SELECT name FROM sqlite_temp_master WHERE type = 'trigger' AND name LIKE '{$triggerPrefix}%'
        ");

        foreach ($triggers as $k => $trigger) {
            if (strpos($trigger, $triggerPrefix) !== 0) {
                unset($triggers[$k]);
            }
        }

        return (array)$triggers;
    }

    /**
     * @inheritDoc
     */
    public function dropTriggers(): void
    {
        $triggers = $this->getTriggers();

        if (!$triggers) {
            return;
        }

        foreach ($triggers as $trigger) {
            /** @var \Cake\Database\Connection $connection */
            $connection = $this->getConnection();
            $connection->execute("DROP TRIGGER {$trigger};");
        }
    }

    /**
     * @param array<string> $tables
     *
     * @return void
     */
    public function dropTables(array $tables): void
    {
        if (!$tables) {
            return;
        }

        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getConnection();
        $connection->disableConstraints(function (Connection $connection) use ($tables) {
            $tables[] = BaseTriggerBasedTableSniffer::DIRTY_TABLE_COLLECTOR;
            $connection->transactional(function (Connection $connection) use ($tables) {
                foreach ($tables as $table) {
                    $connection->execute("DROP TABLE IF EXISTS $table;");
                }
            });
        });
    }
}
