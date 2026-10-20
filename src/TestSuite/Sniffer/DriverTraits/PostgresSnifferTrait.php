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
 * Trait PostgresSnifferTrait
 *
 * @package CakephpFixtureFactories\TestSuite\Sniffer\DriverTraits
 */
trait PostgresSnifferTrait
{
    /**
     * @return array<string>
     */
    public function getTriggers(): array
    {
        $triggerPrefix = BaseTriggerBasedTableSniffer::TRIGGER_PREFIX;
        $triggers = $this->fetchQuery("
            SELECT tgname
            FROM pg_trigger
            WHERE tgname LIKE '{$triggerPrefix}%'
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
        $triggerPrefix = BaseTriggerBasedTableSniffer::TRIGGER_PREFIX;
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getConnection();
        // The table comes from pg_class: a trigger name may be truncated, so it cannot be derived from it.
        $rows = $connection->execute("
            SELECT t.tgname, c.relname
            FROM pg_trigger t
            INNER JOIN pg_class c ON c.oid = t.tgrelid
            WHERE t.tgname LIKE '{$triggerPrefix}%'
        ")->fetchAll();

        foreach ($rows as [$trigger, $table]) {
            $connection->execute("DROP TRIGGER \"{$trigger}\" ON \"{$table}\"");
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
            foreach ($tables as $table) {
                $connection->execute(
                    'DROP TABLE IF EXISTS "' . $table . '" CASCADE;',
                );
            }
        });
    }
}
