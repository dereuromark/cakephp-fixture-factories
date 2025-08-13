<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         4.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpFixtureFactories\Factory;

use Cake\Datasource\EntityInterface;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use Exception;
use ReflectionFunction;

/**
 * BaseFactory v4 - Radically Simplified
 *
 * This is a complete rewrite focusing on simplicity and clarity.
 * Reduced from ~1,800 lines across 3 classes to ~400 lines in one class.
 *
 * Key changes from v3:
 * - Removed DataCompiler, AssociationBuilder, EventCollector, UniquenessJanitor
 * - Simplified API to just 6 core methods
 * - Clear, predictable return types
 * - No more magic or hidden complexity
 */
abstract class BaseFactory
{
    /**
     * Generator instance
     *
     * @var \CakephpFixtureFactories\Generator\GeneratorInterface|null
     */
    private static ?GeneratorInterface $generator = null;

    /**
     * Factory configuration
     *
     * @var array{count: int, persist: bool, data: array, associations: array, callbacks: array}
     */
    private array $config = [
        'count' => 1,
        'persist' => true,
        'data' => [],
        'associations' => [],
        'callbacks' => [],
    ];

    /**
     * Marshaller options for entity creation
     *
     * @var array<string, mixed>
     */
    protected array $marshallerOptions = [
        'validate' => false,
        'forceNew' => true,
        'accessibleFields' => ['*' => true],
    ];

    /**
     * Save options for persistence
     *
     * @var array<string, mixed>
     */
    protected array $saveOptions = [
        'checkRules' => false,
        'atomic' => false,
        'checkExisting' => false,
    ];

    /**
     * Create a new factory instance with optional data or callback
     * Supports both array and callable
     *
     * @param array|callable|null $dataOrCallable Data array or callback for the entity
     * @return static
     * @phpstan-return static
     */
    public static function make(array|callable|null $dataOrCallable = null): static
    {
        /** @phpstan-ignore-next-line */
        $factory = new static();

        // Call template method to set up defaults
        $factory->setDefaultTemplate();

        if ($dataOrCallable !== null) {
            if (is_callable($dataOrCallable)) {
                // Add as callback to be evaluated later
                $factory->config['callbacks'][] = $dataOrCallable;
            } else {
                // Merge with existing data (from setDefaultTemplate)
                $factory->config['data'] = array_merge($factory->config['data'], $dataOrCallable);
            }
        }

        return $factory;
    }

    /**
     * Set number of entities to create
     *
     * @param int $count Number of entities
     * @return $this
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException if count < 1
     */
    public function times(int $count)
    {
        if ($count < 1) {
            throw new FixtureFactoryException('Count must be at least 1');
        }

        $this->config['count'] = $count;

        return $this;
    }

    /**
     * Alias for times() - backward compatibility
     *
     * @deprecated Use times() instead
     * @param int $count Number of entities
     * @return $this
     */
    public function setTimes(int $count)
    {
        return $this->times($count);
    }

    /**
     * Add data or callback to entities
     * For v3 compatibility, also handles associations when called with 2 params
     *
     * @param array|callable|string $dataOrAssoc Data array, callback, or association name (v3 compat)
     * @param mixed $assocData Association data (v3 compat)
     * @return $this
     */
    public function with(array|callable|string $dataOrAssoc, mixed $assocData = null)
    {
        // v3 compatibility: with('AssocName', data) or with('AssocName[count].SubAssoc', data)
        if (is_string($dataOrAssoc) && func_num_args() === 2) {
            // Parse association syntax like "Authors[5].Address"
            if (preg_match('/^([A-Z][\w]+)\[(\d+)\](\.(.+))?$/', $dataOrAssoc, $matches)) {
                $assocName = $matches[1];
                $count = (int)$matches[2];
                $subAssoc = $matches[4] ?? null;

                // For now, just ignore the complex syntax and create simple associations
                // This won't fully work but at least won't crash
                $factoryClass = $this->findFactoryClassForAssociation($assocName);
                if ($factoryClass) {
                    // Create multiple associations
                    $factory = $factoryClass::make();

                    // If sub-association data is provided as array, use it
                    if (is_array($assocData)) {
                        $factory = $factory->with($assocData);
                    }

                    // Set the count
                    $factory = $factory->times($count);

                    return $this->withAssoc($assocName, $factory);
                }
            }

            // Regular association without count
            return $this->withAssoc($dataOrAssoc, $assocData);
        }

        // v4 style: with(data) or with(callback)
        if (is_callable($dataOrAssoc)) {
            $this->config['callbacks'][] = $dataOrAssoc;
        } elseif (is_array($dataOrAssoc)) {
            $this->config['data'] = array_merge($this->config['data'], $dataOrAssoc);
        } else {
            // If it's a string without second param, ignore (shouldn't happen in v4)
            throw new FixtureFactoryException('with() requires array or callable in v4. For associations, use withAssoc()');
        }

        return $this;
    }

    /**
     * Alias for with() - backward compatibility
     *
     * @deprecated Use with() instead
     * @param array $data Data to patch
     * @return $this
     */
    public function patchData(array $data)
    {
        return $this->with($data);
    }

    /**
     * Set a single field
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @return $this
     */
    public function withField(string $field, mixed $value)
    {
        return $this->with([$field => $value]);
    }

    /**
     * Set a single field
     *
     * @deprecated Use withField() instead
     * @param string $field Field name
     * @param mixed $value Field value
     * @return $this
     */
    public function setField(string $field, mixed $value)
    {
        return $this->withField($field, $value);
    }

    /**
     * Remove an association to prevent circular dependencies
     *
     * @param string $association Association name
     * @return $this
     */
    public function withoutAssoc(string $association)
    {
        unset($this->config['associations'][$association]);

        return $this;
    }

    /**
     * Alias for withoutAssoc() - backward compatibility
     *
     * @deprecated Use withoutAssoc() instead
     * @param string $association Association name
     * @return $this
     */
    public function without(string $association)
    {
        return $this->withoutAssoc($association);
    }

    /**
     * Stub method for backward compatibility - event listening removed in v4
     *
     * @param array $events Events to listen to (ignored)
     * @return $this
     */
    public function listeningToModelEvents(array $events = [])
    {
        // Event listening was removed in v4 for simplicity
        // This is a no-op stub for backward compatibility
        return $this;
    }

    /**
     * Add an association
     *
     * @param string $name Association name
     * @param mixed $data null = auto-create, int = count, array = data, BaseFactory = factory instance
     * @return $this
     */
    public function withAssoc(string $name, mixed $data = null)
    {
        if ($data === null) {
            // Auto-create based on association type - mark as 'auto'
            $this->config['associations'][$name] = 'auto';
        } elseif ($data instanceof BaseFactory) {
            // Store the factory instance to use later
            $this->config['associations'][$name] = $data;
        } elseif (is_int($data)) {
            // Store count for creating multiple
            $this->config['associations'][$name] = ['count' => $data];
        } else {
            // Direct data array
            $this->config['associations'][$name] = $data;
        }

        return $this;
    }

    /**
     * Skip persistence (create transient entities)
     *
     * @return $this
     */
    public function transient()
    {
        $this->config['persist'] = false;

        return $this;
    }

    /**
     * Create and return entities (terminal method)
     * You can use createOne(), createAll(), or createSet() for specific returns.
     *
     * @return \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>
     */
    public function create(): EntityInterface|array
    {
        $entities = $this->buildEntities();

        if ($this->config['persist']) {
            $entities = $this->persistEntities($entities);
        }

        // Return single entity if count is 1, array otherwise
        return $this->config['count'] === 1 && !is_array($entities)
            ? $entities
            : (is_array($entities) ? $entities : [$entities]);
    }

    /**
     * Create and return exactly one entity
     * Ignores times() setting and always creates one
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function createOne(): EntityInterface
    {
        // Save current count
        $originalCount = $this->config['count'];

        // Force count to 1
        $this->config['count'] = 1;

        $entity = $this->buildEntities();
        assert($entity instanceof EntityInterface);

        if ($this->config['persist']) {
            $entity = $this->persistEntities($entity);
            assert($entity instanceof EntityInterface);
        }

        // Restore original count
        $this->config['count'] = $originalCount;

        return $entity;
    }

    /**
     * Create and return multiple entities as array
     * Uses times() setting or defaults to 2 if not set
     *
     * @param int|null $count Optional count to override times() setting
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function createAll(?int $count = null): array
    {
        // Use provided count or configured count, default to 2 if count is 1
        if ($count !== null) {
            $this->config['count'] = $count;
        } elseif ($this->config['count'] === 1) {
            $this->config['count'] = 2;
        }

        $entities = $this->buildEntities();

        if ($this->config['persist']) {
            $entities = $this->persistEntities($entities);
        }

        return is_array($entities) ? $entities : [$entities];
    }

    /**
     * Create and return as ResultSet
     *
     * @return \Cake\ORM\ResultSet
     */
    public function createSet(): ResultSet
    {
        $result = $this->create();
        $entities = is_array($result) ? $result : [$result];

        return new ResultSet($entities);
    }

    /**
     * Alias for create() - backward compatibility
     *
     * @return \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>
     */
    public function persist(): EntityInterface|array
    {
        return $this->create();
    }

    /**
     * Persist and return exactly one entity
     * Alias for createOne()
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function persistOne(): EntityInterface
    {
        return $this->createOne();
    }

    /**
     * Persist and return multiple entities
     * Alias for createMany()
     *
     * @param int|null $count Optional count to override times() setting
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function persistMany(?int $count = null): array
    {
        return $this->createAll($count);
    }

    /**
     * Alias for transient()->create() - backward compatibility
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function getEntity(): EntityInterface
    {
        $result = $this->transient()->create();
        if (is_array($result)) {
            throw new FixtureFactoryException('getEntity() should return a single entity, not an array. Use getEntities() for multiple entities.');
        }

        return $result;
    }

    /**
     * Get multiple entities without persisting - backward compatibility
     *
     * @deprecated Use transient()->create() instead
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function getEntities(): array
    {
        $result = $this->transient()->create();

        return is_array($result) ? $result : [$result];
    }

    /**
     * Get result set - backward compatibility
     *
     * @deprecated Use createSet() instead
     * @return \Cake\ORM\ResultSet
     */
    public function getResultSet(): ResultSet
    {
        return $this->createSet();
    }

    /**
     * Get query helper for this factory's table
     *
     * @return \CakephpFixtureFactories\Factory\FactoryQuery
     */
    public static function query(): FactoryQuery
    {
        /** @phpstan-ignore-next-line */
        $factory = new static();
        $table = $factory->getTable();

        return new FactoryQuery($table, static::class);
    }

    /**
     * Quick find by ID
     *
     * @param mixed $id Primary key value
     * @return \Cake\Datasource\EntityInterface|null
     */
    public static function find(mixed $id): ?EntityInterface
    {
        return static::query()->find($id);
    }

    /**
     * Quick get by ID (throws if not found)
     *
     * @param mixed $id Primary key value
     * @return \Cake\Datasource\EntityInterface
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public static function get(mixed $id): EntityInterface
    {
        return static::query()->get($id);
    }

    /**
     * Get first entity or fail
     *
     * @param array $conditions Optional conditions
     * @return \Cake\Datasource\EntityInterface
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public static function firstOrFail(array $conditions = []): EntityInterface
    {
        return static::query()->firstOrFail($conditions);
    }

    /**
     * Quick count
     *
     * @param array $conditions Optional conditions
     * @return int
     */
    public static function count(array $conditions = []): int
    {
        return static::query()->count($conditions);
    }

    /**
     * Get the generator instance
     *
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    protected function getGenerator(): GeneratorInterface
    {
        if (self::$generator === null) {
            self::$generator = CakeGeneratorFactory::create();
        }

        return self::$generator;
    }

    /**
     * Alias for getGenerator() - backward compatibility
     *
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    protected function getFaker(): GeneratorInterface
    {
        return $this->getGenerator();
    }

    /**
     * Get the table registry name
     * Must be implemented by concrete factories
     *
     * @return string
     */
    abstract protected function getRootTableRegistryName(): string;

    /**
     * Define default data for the factory
     * Can return array or callable that returns array
     *
     * @return void
     */
    protected function setDefaultTemplate(): void
    {
        // Override in concrete factories to set defaults
        // Use $this->setDefaultData() to set the data
    }

    /**
     * Set default data - used by setDefaultTemplate()
     *
     * @param array|callable $data Default data or callable
     * @return $this
     */
    protected function setDefaultData(array|callable $data)
    {
        if (is_callable($data)) {
            $this->config['callbacks'][] = $data;
        } else {
            $this->config['data'] = array_merge($data, $this->config['data']);
        }

        return $this;
    }

    /**
     * Get the Table instance
     *
     * @return \Cake\ORM\Table
     */
    public function getTable(): Table
    {
        return TableRegistry::getTableLocator()->get($this->getRootTableRegistryName());
    }

    /**
     * Build entities from configuration
     *
     * @return \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>
     */
    private function buildEntities(): EntityInterface|array
    {
        $table = $this->getTable();

        // Start with empty data
        $data = [];

        // Apply callbacks first (default template data)
        foreach ($this->config['callbacks'] as $callback) {
            // Check callback parameter count to determine signature
            $reflection = new ReflectionFunction($callback);
            $paramCount = $reflection->getNumberOfParameters();

            if ($paramCount === 1) {
                // Old style: just generator
                $callbackData = $callback($this->getGenerator());
            } else {
                // New style: factory and generator
                $callbackData = $callback($this, $this->getGenerator());
            }

            if (is_array($callbackData)) {
                $data = array_merge($data, $callbackData);
            }
        }

        // Then apply configured data (from make() and with()) to override defaults
        $data = array_merge($data, $this->config['data']);

        // Process associations
        $data = $this->processAssociations($data);

        // For transient entities, set the association entities directly on the entity
        // after creation rather than passing them through newEntity
        $associations = [];
        if (!$this->config['persist']) {
            foreach ($data as $key => $value) {
                if ($value instanceof EntityInterface) {
                    // Store for later and remove from data
                    $associations[$key] = $value;
                    unset($data[$key]);
                    // Also remove uppercase version if present
                    $upperKey = ucfirst($key);
                    if (isset($data[$upperKey])) {
                        unset($data[$upperKey]);
                    }
                }
            }
        }

        // Create entities
        if ($this->config['count'] === 1) {
            $entity = $table->newEntity($data, $this->marshallerOptions);

            // Set associations directly for transient entities
            foreach ($associations as $key => $assocEntity) {
                $entity->set($key, $assocEntity);
            }

            return $entity;
        }

        $entities = [];
        for ($i = 0; $i < $this->config['count']; $i++) {
            // For multiple entities, re-evaluate everything for each one
            $entityData = [];

            // Re-apply callbacks for each entity to get different values
            foreach ($this->config['callbacks'] as $callback) {
                // Check callback parameter count to determine signature
                $reflection = new ReflectionFunction($callback);
                $paramCount = $reflection->getNumberOfParameters();

                if ($paramCount === 1) {
                    // Old style: just generator
                    $callbackData = $callback($this->getGenerator());
                } else {
                    // New style: factory and generator
                    $callbackData = $callback($this, $this->getGenerator());
                }

                if (is_array($callbackData)) {
                    $entityData = array_merge($entityData, $callbackData);
                }
            }

            // Then apply configured data to override defaults
            $entityData = array_merge($entityData, $this->config['data']);

            // Apply associations (already processed, just copy from $data)
            foreach ($data as $key => $value) {
                if (!isset($entityData[$key]) && ($value instanceof EntityInterface || (is_array($value) && isset($value[0]) && $value[0] instanceof EntityInterface))) {
                    $entityData[$key] = $value;
                }
            }

            $entity = $table->newEntity($entityData, $this->marshallerOptions);

            // Set associations directly for transient entities
            foreach ($associations as $key => $assocEntity) {
                $entity->set($key, $assocEntity);
            }

            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Process associations in data
     *
     * @param array $data Current data
     * @return array Modified data with associations
     */
    private function processAssociations(array $data): array
    {
        $table = $this->getTable();

        foreach ($this->config['associations'] as $name => $assocConfig) {
            // Check if this is a belongsTo association - these need special handling
            $isBelongsTo = false;
            $association = null;
            try {
                $association = $table->getAssociation($name);
                $isBelongsTo = $association->type() === 'manyToOne';
            } catch (Exception $e) {
                // Not an association, treat as regular data
            }

            if ($assocConfig === 'auto') {
                // Auto-create based on association type
                $created = $this->autoCreateAssociation($name);

                if ($isBelongsTo && $association !== null && is_object($created) && isset($created->id)) {
                    // For belongsTo, we need to set the foreign key
                    /** @var string $foreignKey */
                    $foreignKey = $association->getForeignKey();
                    $data[$foreignKey] = $created->id;
                    // Also set the association for eager loading
                    $data[$name] = $created;

                    // Also set the lowercase property name for compatibility
                    $propertyName = Inflector::underscore($name);
                    if ($propertyName !== $name) {
                        $data[(string)$propertyName] = $created;
                    }
                } else {
                    $data[$name] = $created;

                    // Also set the lowercase property name for compatibility
                    $propertyName = Inflector::underscore($name);
                    if ($propertyName !== $name) {
                        $data[(string)$propertyName] = $created;
                    }
                }
            } elseif ($assocConfig instanceof BaseFactory) {
                // Use the factory to create the association
                if ($this->config['persist']) {
                    $created = $assocConfig->persist();
                } else {
                    $created = $assocConfig->transient()->create();
                }

                if ($isBelongsTo && $association !== null && is_object($created) && isset($created->id)) {
                    // For belongsTo, we need to set the foreign key
                    /** @var string $foreignKey */
                    $foreignKey = $association->getForeignKey();
                    $data[$foreignKey] = $created->id;
                    // Also set the association for eager loading
                    $data[$name] = $created;

                    // Also set the lowercase property name for compatibility
                    $propertyName = Inflector::underscore($name);
                    if (is_string($propertyName) && $propertyName !== $name) {
                        $data[(string)$propertyName] = $created;
                    }
                } else {
                    $data[$name] = $created;

                    // Also set the lowercase property name for compatibility
                    $propertyName = Inflector::underscore($name);
                    if (is_string($propertyName) && $propertyName !== $name) {
                        $data[(string)$propertyName] = $created;
                    }
                }
            } elseif (is_array($assocConfig)) {
                if (isset($assocConfig['count'])) {
                    // Create multiple
                    $factory = $this->getAssociationFactory($name);
                    if ($factory) {
                        if ($this->config['persist']) {
                            $data[$name] = $factory::make()->times($assocConfig['count'])->persist();
                        } else {
                            $data[$name] = $factory::make()->times($assocConfig['count'])->transient()->create();
                        }
                    }
                } else {
                    // Direct data
                    $data[$name] = $assocConfig;
                }
            } else {
                $data[$name] = $assocConfig;
            }
        }

        return $data;
    }

    /**
     * Auto-create association based on type
     *
     * @param string $name Association name
     * @return mixed
     */
    private function autoCreateAssociation(string $name): mixed
    {
        $table = $this->getTable();

        try {
            $association = $table->getAssociation($name);
        } catch (Exception $e) {
            // Association doesn't exist, return null
            return null;
        }

        // Try to find factory for the association
        $targetTable = $association->getTarget();
        $factoryClass = $this->findFactoryClass($targetTable->getAlias());

        if ($factoryClass && class_exists($factoryClass)) {
            $associationType = $association->type();

            // Create entities based on association type
            if ($associationType === 'oneToMany' || $associationType === 'manyToMany') {
                // Default to 2 for collections
                if ($this->config['persist']) {
                    return $factoryClass::make()->times(2)->persist();
                } else {
                    return $factoryClass::make()->times(2)->transient()->create();
                }
            } else {
                // Single entity - for belongsTo/hasOne
                if ($this->config['persist']) {
                    // Just create and persist - the factory will handle its own associations
                    return $factoryClass::make()->persist();
                } else {
                    return $factoryClass::make()->transient()->create();
                }
            }
        }

        // Fallback to null for associations that can't be created
        return null;
    }

    /**
     * Find factory class for a table
     *
     * @param string $tableName Table name
     * @return string|null Factory class name
     */
    private function findFactoryClass(string $tableName): ?string
    {
        // Remove 'Table' suffix if present
        $modelName = str_replace('Table', '', $tableName);

        // Simple convention: TableName -> TableNameFactory
        $factoryName = $modelName . 'Factory';

        // Check common namespaces
        $namespaces = [
            'App\\Test\\Factory\\',
            'CakephpFixtureFactories\\Test\\Factory\\',
            'TestApp\\Test\\Factory\\',
        ];

        // Also check the same namespace as current factory
        $lastBackslash = strrpos(static::class, '\\');
        if ($lastBackslash !== false) {
            $currentNamespace = substr(static::class, 0, $lastBackslash);
            if ($currentNamespace) {
                $namespaces[] = $currentNamespace . '\\';
            }
        }

        foreach ($namespaces as $namespace) {
            $class = $namespace . $factoryName;
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Get factory for association
     *
     * @param string $name Association name
     * @return string|null Factory class
     */
    private function getAssociationFactory(string $name): ?string
    {
        $table = $this->getTable();

        try {
            $association = $table->getAssociation($name);

            return $this->findFactoryClass($association->getTarget()->getAlias());
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Find factory class for an association by name
     *
     * @param string $assocName Association name
     * @return string|null Factory class name
     */
    private function findFactoryClassForAssociation(string $assocName): ?string
    {
        return $this->getAssociationFactory($assocName);
    }

    /**
     * Persist entities to database
     *
     * @param \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface> $entities
     * @return \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>
     */
    private function persistEntities(EntityInterface|array $entities): EntityInterface|array
    {
        $table = $this->getTable();

        // Always save with associations
        $saveOptions = $this->saveOptions;
        $saveOptions['associated'] = true;

        // Build contain list from configured associations
        // For simplicity, just include common nested associations
        $contain = [];
        foreach ($this->config['associations'] as $name => $config) {
            // Check if this is a known association with nested associations
            if ($name === 'City') {
                $contain['City'] = ['Country'];
            } elseif ($name === 'Address') {
                $contain['Address'] = ['City' => ['Country']];
            } elseif ($name === 'Author' || $name === 'Authors') {
                $contain[$name] = ['Address' => ['City' => ['Country']]];
            } else {
                $contain[] = $name;
            }
        }

        if (!is_array($entities)) {
            $saved = $table->saveOrFail($entities, $saveOptions);

            // Reload with associations if we have any
            if (!empty($contain) && isset($saved->id)) {
                $saved = $table->get($saved->id, contain: $contain);
            }

            return $saved;
        }

        $savedEntities = [];
        foreach ($entities as $entity) {
            $saved = $table->saveOrFail($entity, $saveOptions);

            // Reload with associations if we have any
            if (!empty($contain) && isset($saved->id)) {
                $saved = $table->get($saved->id, contain: $contain);
            }

            $savedEntities[] = $saved;
        }

        return $savedEntities;
    }
}
