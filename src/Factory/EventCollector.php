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

namespace CakephpFixtureFactories\Factory;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventManagerInterface;
use Cake\ORM\Table;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use RuntimeException;

/**
 * Class EventCollector
 *
 * @internal
 */
class EventCollector
{
    /**
     * @var string
     */
    public const MODEL_EVENTS = 'CakephpFixtureFactoriesListeningModelEvents';

    /**
     * @var string
     */
    public const MODEL_BEHAVIORS = 'CakephpFixtureFactoriesListeningBehaviors';

    /**
     * @var \Cake\ORM\Table|null
     */
    private ?Table $table = null;

    /**
     * @var array<string>
     */
    private array $listeningBehaviors = [];

    /**
     * @var array<string>
     */
    private array $listeningModelEvents = [];

    /**
     * @var array<string>
     */
    private array $defaultListeningBehaviors = [];

    /**
     * @var string
     */
    private string $rootTableRegistryName;

    /**
     * @var string|null
     */
    private ?string $connectionName = null;

    /**
     * @var \Cake\Event\EventManagerInterface|null
     */
    private ?EventManagerInterface $eventManager = null;

    /**
     * @param string $rootTableRegistryName Name of the model of the master factory
     */
    public function __construct(string $rootTableRegistryName)
    {
        $this->rootTableRegistryName = $rootTableRegistryName;
        $this->setDefaultListeningBehaviors();
    }

    /**
     * Create a table cloned from the TableRegistry
     * and per default without Model Events.
     *
     * @return \Cake\ORM\Table
     */
    public function getTable(): Table
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $options = [
            self::MODEL_EVENTS => $this->getListeningModelEvents(),
            self::MODEL_BEHAVIORS => $this->getListeningBehaviors(),
        ];

        if ($this->connectionName !== null) {
            $options['connection'] = ConnectionManager::get($this->connectionName);
        }

        try {
            $table = FactoryTableRegistry::getTableLocator()->get($this->rootTableRegistryName, $options);
        } catch (RuntimeException $exception) {
            FactoryTableRegistry::getTableLocator()->remove($this->rootTableRegistryName);
            $table = FactoryTableRegistry::getTableLocator()->get($this->rootTableRegistryName, $options);
        }

        if ($this->eventManager !== null) {
            $table->setEventManager($this->eventManager);
        }

        return $this->table = $table;
    }

    /**
     * @return array<string>
     */
    public function getListeningBehaviors(): array
    {
        return $this->listeningBehaviors;
    }

    /**
     * Set the database connection to use for the table.
     *
     * @param string $connectionName Name of the database connection
     *
     * @return $this
     */
    public function setConnection(string $connectionName)
    {
        $this->table = null;
        $this->connectionName = $connectionName;

        return $this;
    }

    /**
     * Set a custom event manager for the factory's table.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager Custom event manager
     *
     * @return $this
     */
    public function setEventManager(EventManagerInterface $eventManager)
    {
        $this->table = null;
        $this->eventManager = $eventManager;

        return $this;
    }

    /**
     * @param array<string> $activeBehaviors Behaviors the factory will listen to
     *
     * @return array<string>
     */
    public function listeningToBehaviors(array $activeBehaviors): array
    {
        $this->table = null;

        return $this->listeningBehaviors = array_merge($this->defaultListeningBehaviors, $activeBehaviors);
    }

    /**
     * @param array<string> $activeModelEvents Events the factory will listen to
     *
     * @return array<string>
     */
    public function listeningToModelEvents(array $activeModelEvents): array
    {
        $this->table = null;

        return $this->listeningModelEvents = $activeModelEvents;
    }

    /**
     * @return array<string>
     */
    public function getListeningModelEvents(): array
    {
        return $this->listeningModelEvents;
    }

    /**
     * @return void
     */
    protected function setDefaultListeningBehaviors(): void
    {
        $defaultBehaviors = (array)Configure::read('FixtureFactories.testFixtureGlobalBehaviors', []);
        $defaultBehaviors[] = 'Timestamp';
        $this->defaultListeningBehaviors = $defaultBehaviors;
        $this->listeningBehaviors = $defaultBehaviors;
    }

    /**
     * @return array<string>
     */
    public function getDefaultListeningBehaviors(): array
    {
        return $this->defaultListeningBehaviors;
    }
}
