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

namespace CakephpFixtureFactories\ORM;

use Cake\Core\Configure;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use CakephpFixtureFactories\Event\ModelEventsHandler;
use CakephpFixtureFactories\Factory\EventCollector;

/**
 * Class FactoryTableLocator
 *
 * @internal
 */
class FactoryTableLocator extends TableLocator
{
    /**
     * @inheritDoc
     */
    protected function createInstance(string $alias, array $options): Table
    {
        if (!array_key_exists('associations', $options)) {
            $options['associations'] = $this->getConfig($alias)['associations'] ?? null;
        }
        $options['associations'] ??= new FactoryAssociationCollection($this);

        return parent::createInstance($alias, $options);
    }

    /**
     * @inheritDoc
     */
    protected function create(array $options): Table
    {
        $table = parent::create($options);

        $defaultBehaviors = (array)Configure::read('FixtureFactories.testFixtureGlobalBehaviors', []);
        $defaultBehaviors[] = 'Timestamp';

        $behaviors = array_merge($options[EventCollector::MODEL_BEHAVIORS] ?? [], $defaultBehaviors);

        ModelEventsHandler::handle(
            $table,
            $options[EventCollector::MODEL_EVENTS] ?? [],
            $behaviors,
        );

        $table->getEventManager()->on('Model.beforeSave', function ($event, $entity, $options) use ($table): void {
            FactoryTableBeforeSave::handle($table, $entity);
        });

        return $table;
    }
}
