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
            'className' => $this->rootTableRegistryName,
        ];
        if ($this->eventManager !== null) {
            $options['eventManager'] = $this->eventManager;
        }
        $registryAlias = $this->getScopedRegistryAlias();

        if ($this->connectionName !== null) {
            $options['connection'] = ConnectionManager::get($this->connectionName);
        }

        $locator = FactoryTableRegistry::getTableLocator();

        try {
            $table = $locator->get($registryAlias, $options);
        } catch (RuntimeException $exception) {
            if (!$locator->exists($registryAlias)) {
                throw $exception;
            }
            $locator->remove($registryAlias);
            $table = $locator->get($registryAlias, $options);
        }
        $table->setAlias($this->getRootTableAlias());

        // Mirror the factory's scoped instance under the bare alias and the
        // plugin-prefixed alias so CakePHP's BTM target lookups and
        // junction-belongsTo resolutions — which always use those forms,
        // never the scoped key — return this exact instance instead of
        // spawning a parallel one under the same logical name.
        //
        // Without this consolidation the junction's belongsTo target
        // (registered by Cake's `_generateJunctionAssociations` source-side
        // block with `targetTable: $source` = this scoped factory table)
        // drifts away from the bare-alias instance the inverse direction
        // creates on its first BTM target lookup. Cake then reaches the
        // identity check at `BelongsToMany::_generateJunctionAssociations`
        // and throws the "incompatible association on junction" error
        // reported in #62.
        //
        // The set is unconditional: factories that resolve to the same
        // scoped key (default-listening factories — the common case) all
        // map their bare alias here to the same instance, so successive
        // calls are no-ops. Custom-listening factories overwrite, which
        // is intentional — the factory that ran most recently owns the
        // bare alias, matching the behavior CakePHP itself documents for
        // explicit `set()` calls on the table locator.
        foreach ([$this->getRootTableAlias(), $this->rootTableRegistryName] as $alias) {
            if ($alias === $registryAlias) {
                continue;
            }
            $locator->set($alias, $table);
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

    /**
     * Scope factory tables by listening options so different factories do not
     * share and mutate the same stripped-down Table instance.
     *
     * @return string
     */
    private function getScopedRegistryAlias(): string
    {
        $hash = hash('sha256', serialize([
            'table' => $this->rootTableRegistryName,
            'connection' => $this->connectionName,
            'behaviors' => $this->listeningBehaviors,
            'events' => $this->listeningModelEvents,
            'eventManager' => $this->eventManager ? spl_object_id($this->eventManager) : null,
        ]));

        return sprintf('%s__ff_%s', $this->getRootTableAlias(), substr($hash, 0, 8));
    }

    /**
     * Keep the public table alias aligned with Cake's conventional alias, even
     * when the registry key is scoped for factory options.
     *
     * @return string
     */
    private function getRootTableAlias(): string
    {
        $parts = explode('.', $this->rootTableRegistryName);

        return (string)end($parts);
    }
}
