<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\TestSuite\Sniffer\DriverTraits;

use Cake\Database\Connection;
use CakephpFixtureFactories\TestSuite\Sniffer\BaseTriggerBasedTableSniffer;

/**
 * Trait MysqlSnifferTrait
 *
 * @package CakephpFixtureFactories\TestSuite\Sniffer\DriverTraits
 */
trait MysqlSnifferTrait
{
    /**
     * @return array<string>
     */
    public function getTriggers(): array
    {
        $triggers = $this->fetchQuery('SHOW triggers');

        foreach ($triggers as $k => $trigger) {
            if (strpos($trigger, BaseTriggerBasedTableSniffer::TRIGGER_PREFIX) !== 0) {
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

        /** @var \Cake\Database\Connection $connection */
        $connection = $this->getConnection();
        foreach ($triggers as $trigger) {
            $connection->execute('DROP TRIGGER ' . $trigger);
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
            $connection->execute('DROP TABLE IF EXISTS `' . implode('`, `', $tables) . '`');
        });
    }
}
