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

use Cake\Database\ExpressionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\I18n\I18n;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use Closure;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use function array_merge;
use function is_array;

/**
 * Class BaseFactory
 *
 * @package CakephpFixtureFactories\Factory
 */
abstract class BaseFactory
{
    /**
     * @var \CakephpFixtureFactories\Generator\GeneratorInterface|null
     */
    private static ?GeneratorInterface $generator = null;

    /**
     * @var array<string, mixed>
     */
    protected array $marshallerOptions = [
        'validate' => false,
        'forceNew' => true,
        'accessibleFields' => ['*' => true],
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $saveOptions = [
        'checkRules' => false,
        'atomic' => false,
        'checkExisting' => false,
    ];

    /**
     * @var array<string> Unique fields. Uniqueness applies only to persisted entities.
     */
    protected array $uniqueProperties = [];

    /**
     * @var array<string> Fields on which the setters should be skipped.
     */
    protected array $skippedSetters = [];

    /**
     * The number of records the factory should create
     *
     * @var int
     */
    private int $times = 1;

    /**
     * Keep entities dirty so they can be saved manually.
     *
     * @var bool
     */
    private bool $keepDirty = false;

    /**
     * The data compiler gathers the data from the
     * default template, the injection and patched data
     * and compiles it to produce the data feeding the
     * entities of the Factory
     *
     * @var \CakephpFixtureFactories\Factory\DataCompiler
     */
    private DataCompiler $dataCompiler;

    /**
     * Helper to check and build data in associations
     *
     * @var \CakephpFixtureFactories\Factory\AssociationBuilder
     */
    private AssociationBuilder $associationBuilder;

    /**
     * Handles the events at the model and behavior level
     * for the table on which the factories will be built
     *
     * @var \CakephpFixtureFactories\Factory\EventCollector
     */
    private EventCollector $eventCompiler;

    final protected function __construct()
    {
        $this->dataCompiler = new DataCompiler($this);
        $this->associationBuilder = new AssociationBuilder($this);
        $this->eventCompiler = new EventCollector($this->getRootTableRegistryName());
    }

    /**
     * Table Registry the factory is building entities from
     *
     * @return string
     */
    abstract protected function getRootTableRegistryName(): string;

    /**
     * @return void
     */
    abstract protected function setDefaultTemplate(): void;

    /**
     * @param mixed $makeParameter Injected data
     * @param int $times Number of entities created
     *
     * @throws \InvalidArgumentException
     *
     * @return static
     */
    public static function make(
        mixed $makeParameter = [],
        int $times = 1,
    ): self {
        if (is_numeric($makeParameter)) {
            $factory = self::makeFromNonCallable();
            $times = $makeParameter;
        } elseif ($makeParameter === null) {
            $factory = self::makeFromNonCallable();
        } elseif (is_array($makeParameter) || $makeParameter instanceof EntityInterface || is_string($makeParameter)) {
            $factory = self::makeFromNonCallable($makeParameter);
        } elseif (is_callable($makeParameter)) {
            $factory = self::makeFromCallable($makeParameter);
        } else {
            throw new InvalidArgumentException('
                ::make only accepts an array, an integer, an EntityInterface, a string or a callable as first parameter.
            ');
        }

        $factory->setUp($factory, (int)$times);

        return $factory;
    }

    /**
     * Create a factory that will generate many entities.
     *
     * @param int $times Number of entities.
     *
     * @return static
     */
    public static function makeMany(int $times): self
    {
        return static::make($times);
    }

    /**
     * Create a factory with a callable data provider.
     *
     * @param callable $fn Callable returning array data.
     *
     * @return static
     */
    public static function makeWith(callable $fn): self
    {
        return static::make($fn);
    }

    /**
     * Create a factory from an existing entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Injected entity.
     *
     * @return static
     */
    public static function makeFrom(EntityInterface $entity): self
    {
        return static::make($entity);
    }

    /**
     * Collect the number of entities to be created
     * Apply the default template in the factory
     *
     * @param \CakephpFixtureFactories\Factory\BaseFactory $factory Factory
     * @param int $times Number of entities created
     *
     * @return void
     */
    protected function setUp(BaseFactory $factory, int $times): void
    {
        $factory->initialize();
        $factory->setTimes($times);
        $factory->setDefaultTemplate();
        $factory->getDataCompiler()->collectAssociationsFromDefaultTemplate();
    }

    /**
     * This method may be used to define associations
     * missing in your model but useful to build factories
     *
     * @return void
     */
    protected function initialize(): void
    {
        // Add logic prior to generating the default template.
    }

    /**
     * @param \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>|string $data Injected data
     *
     * @return static
     */
    private static function makeFromNonCallable(EntityInterface|array|string $data = []): self
    {
        $factory = new static();
        $factory->getDataCompiler()->collectFromInstantiation($data);

        return $factory;
    }

    /**
     * @param callable $fn Injected data
     *
     * @return static
     */
    private static function makeFromCallable(callable $fn): self
    {
        $factory = new static();
        $factory->getDataCompiler()->collectArrayFromCallable($fn);

        return $factory;
    }

    /**
     * Get the generator instance, using the locale from I18n
     *
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    public function getGenerator(): GeneratorInterface
    {
        if (self::$generator === null) {
            $locale = I18n::getLocale();
            self::$generator = CakeGeneratorFactory::create($locale);
            self::$generator->seed(1234);
        }

        return self::$generator;
    }

    /**
     * Get the generator instance, using the locale from I18n
     *
     * @deprecated 3.1.0 Use getGenerator() instead. Will be removed in v4.0
     *
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    public function getFaker(): GeneratorInterface
    {
        return $this->getGenerator();
    }

    /**
     * Set the generator type to use for this factory
     *
     * @param string $type The generator type ('faker' or 'dummy')
     * @param string|null $locale Optional locale override
     *
     * @return $this
     */
    public function setGenerator(string $type, ?string $locale = null)
    {
        $locale = $locale ?? I18n::getLocale();
        self::$generator = CakeGeneratorFactory::create($locale, $type);
        self::$generator->seed(1234);

        return $this;
    }

    /**
     * Produce one entity from the present factory
     *
     * @deprecated Use getResultSet instead. Will be removed in v4.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function getEntity(): EntityInterface
    {
        return $this->toArray()[0];
    }

    /**
     * Produce a set of entities from the present factory
     *
     * @deprecated Use getResultSet instead. Will be removed in v4.
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function getEntities(): array
    {
        return $this->toArray();
    }

    /**
     * Creates a result set of non-persisted entities
     *
     * @return \Cake\ORM\ResultSet<int, \Cake\Datasource\EntityInterface>
     */
    public function getResultSet(): ResultSet
    {
        return new ResultSet($this->toArray());
    }

    /**
     * Creates a result set of persisted entities
     *
     * @return \Cake\ORM\ResultSet<int, \Cake\Datasource\EntityInterface>
     */
    public function getPersistedResultSet(): ResultSet
    {
        return new ResultSet((array)$this->persist());
    }

    /**
     * @return array<string, mixed>
     */
    public function getMarshallerOptions(): array
    {
        $associated = $this->getAssociationBuilder()->getAssociated();
        if ($associated) {
            return array_merge($this->marshallerOptions, compact('associated'));
        }

        return $this->marshallerOptions;
    }

    /**
     * @deprecated will be removed in v4
     *
     * @return array<string, mixed>
     */
    public function getAssociated(): array
    {
        return $this->getAssociationBuilder()->getAssociated();
    }

    /**
     * Fetch entities from the data compiler.
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    protected function toArray(): array
    {
        $dataCompiler = $this->getDataCompiler();
        // Casts the default property to array
        $this->skipSetterFor($this->skippedSetters);
        $dataCompiler->setSkippedSetters($this->skippedSetters);
        $entities = [];
        for ($i = 0; $i < $this->times; $i++) {
            $compiledData = $dataCompiler->getCompiledTemplateData();
            if (is_array($compiledData)) {
                $entities = array_merge($entities, $compiledData);
            } else {
                $entities[] = $compiledData;
            }
        }
        UniquenessJanitor::sanitizeEntityArray($this, $entities);

        // Mark entities as clean so their current state becomes the "original" state
        // Only do this when NOT in persist mode, as clean entities can't be saved
        if (!$dataCompiler->isInPersistMode() && !$this->keepDirty) {
            foreach ($entities as $entity) {
                $entity->clean();
            }
        }

        return $entities;
    }

    /**
     * The table on which the factories are build, the package's one
     *
     * @return \Cake\ORM\Table
     */
    public function getTable(): Table
    {
        return $this->getEventCompiler()->getTable();
    }

    /**
     * @deprecated Use getPersistedResultSet. Will be removed in v4.
     *
     * @throws \CakephpFixtureFactories\Error\PersistenceException if the entity/entities could not be saved.
     *
     * @return \Cake\Datasource\EntityInterface|\Cake\Datasource\ResultSetInterface<int, \Cake\Datasource\EntityInterface>|iterable<\Cake\Datasource\EntityInterface>
     */
    public function persist(): EntityInterface|iterable|ResultSetInterface
    {
        $this->getDataCompiler()->startPersistMode();
        try {
            $entities = $this->toArray();
        } finally {
            $this->getDataCompiler()->endPersistMode();
        }

        // Track this table for transaction/cleanup strategies
        $table = $this->getTable();
        FactoryTableTracker::getInstance()->trackTable($table);

        try {
            if (count($entities) === 1) {
                return $table->saveOrFail($entities[0], $this->getSaveOptions());
            }

            return $table->saveManyOrFail($entities, $this->getSaveOptions());
        } catch (Throwable $exception) {
            $factory = static::class;
            $message = $exception->getMessage();

            throw new PersistenceException("Error in Factory `$factory`.\n Message: $message \n");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSaveOptions(): array
    {
        return array_merge($this->saveOptions, [
            'associated' => $this->getAssociationBuilder()->getAssociated(),
        ]);
    }

    /**
     * Assigns the values of $data to the $keys of the entities generated
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $data Data to inject
     *
     * @return $this
     */
    public function patchData(array|EntityInterface $data)
    {
        if ($data instanceof EntityInterface) {
            $data = $data->toArray();
        }
        $this->getDataCompiler()->collectFromPatch($data);

        return $this;
    }

    /**
     * Sets the value for a single field
     *
     * @param string $field to set
     * @param mixed $value to assign
     *
     * @return $this
     */
    public function setField(string $field, mixed $value)
    {
        $this->patchData([$field => $value]);

        return $this;
    }

    /**
     * A protected class dedicated to generating / collecting data for this factory
     *
     * @return \CakephpFixtureFactories\Factory\DataCompiler
     */
    protected function getDataCompiler(): DataCompiler
    {
        return $this->dataCompiler;
    }

    /**
     * A protected class dedicated to building / collecting associations for this factory
     *
     * @return \CakephpFixtureFactories\Factory\AssociationBuilder
     */
    protected function getAssociationBuilder(): AssociationBuilder
    {
        return $this->associationBuilder;
    }

    /**
     * A protected class to manage the Model Events inherent to the creation of fixtures
     *
     * @return \CakephpFixtureFactories\Factory\EventCollector
     */
    protected function getEventCompiler(): EventCollector
    {
        return $this->eventCompiler;
    }

    /**
     * Get the amount of entities generated by the factory
     *
     * @return int
     */
    public function getTimes(): int
    {
        return $this->times;
    }

    /**
     * Set the amount of entities generated by the factory
     *
     * @param int $times Number if entities created
     *
     * @return $this
     */
    public function setTimes(int $times)
    {
        $this->times = $times;

        return $this;
    }

    /**
     * Keep the resulting entities dirty for manual saves.
     *
     * @param bool $keepDirty Whether to keep entities dirty.
     *
     * @return $this
     */
    public function keepDirty(bool $keepDirty = true)
    {
        $this->keepDirty = $keepDirty;
        if ($keepDirty) {
            foreach ($this->getAssociationBuilder()->getAssociations() as $associationFactory) {
                $associationFactory->keepDirty();
            }
        }

        return $this;
    }

    /**
     * @param array<string>|string $activeBehaviors Behaviors listened to by the factory
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException on argument passed error
     *
     * @return $this
     */
    public function listeningToBehaviors(array|string $activeBehaviors)
    {
        $activeBehaviors = (array)$activeBehaviors;
        if (!$activeBehaviors) {
            throw new FixtureFactoryException('Expecting a non empty string or an array of string.');
        }
        $this->getEventCompiler()->listeningToBehaviors($activeBehaviors);

        return $this;
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
        $this->getEventCompiler()->setConnection($connectionName);

        return $this;
    }

    /**
     * @param array<string>|string $activeModelEvents Model events listened to by the factory
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException on argument passed error
     *
     * @return $this
     */
    public function listeningToModelEvents(array|string $activeModelEvents)
    {
        $activeModelEvents = (array)$activeModelEvents;
        if (!$activeModelEvents) {
            throw new FixtureFactoryException('Expecting a non empty string or an array of string.');
        }
        $this->getEventCompiler()->listeningToModelEvents($activeModelEvents);

        return $this;
    }

    /**
     * Set an offset for the Ids of the entities
     * persisted by this factory. This can be an array of type
     * [
     *      composite_key_1 => value1,
     *      composite_key_2 => value2,
     *      ...
     * ]
     * If not set, the offset is set randomly
     *
     * @param array<string, int|string>|string|int $primaryKeyOffset Offset
     *
     * @return $this
     */
    public function setPrimaryKeyOffset(int|string|array $primaryKeyOffset)
    {
        $this->getDataCompiler()->setPrimaryKeyOffset($primaryKeyOffset);

        return $this;
    }

    /**
     * Will not set primary key when saving the entity, instead SQL engine can handle that.
     *
     * @return $this
     */
    public function disablePrimaryKeyOffset()
    {
        $this->getDataCompiler()->disablePrimaryKeyOffset();

        return $this;
    }

    /**
     * Get the fields that are declared are unique.
     * This should include the uniqueness of the fields in your schema.
     *
     * @return array<string>
     */
    public function getUniqueProperties(): array
    {
        return $this->uniqueProperties;
    }

    /**
     * Set the unique fields of the factory.
     * If a field is unique and explicitly modified,
     * it's existence will be checked
     * before persisting. If found, no new
     * entity will be created, but instead the
     * existing one will be considered.
     *
     * @param array<string>|string|null $fields Unique fields set on the fly.
     *
     * @return $this
     */
    public function setUniqueProperties(array|string|null $fields)
    {
        $this->uniqueProperties = (array)$fields;

        return $this;
    }

    /**
     * Populate the entity factored
     *
     * @param callable $fn Callable delivering injected data
     *
     * @return $this
     */
    protected function setDefaultData(callable $fn)
    {
        $this->getDataCompiler()->collectFromDefaultTemplate($fn);

        return $this;
    }

    /**
     * Add associated entities to the fixtures generated by the factory
     * The associated name can be of several depth, dot separated
     * The data can be an array, an integer, an entity interface, a callable or a factory
     *
     * @param string $associationName Association name
     * @param \CakephpFixtureFactories\Factory\BaseFactory|\Cake\Datasource\EntityInterface|callable|array<string, mixed>|string|int $data Injected data
     *
     * @return $this
     */
    public function with(string $associationName, array|int|callable|BaseFactory|EntityInterface|string $data = [])
    {
        $this->getAssociationBuilder()->getAssociation($associationName);

        if (!str_contains($associationName, '.') && $data instanceof BaseFactory) {
            $factory = $data;
        } else {
            $factory = $this->getAssociationBuilder()->getAssociatedFactory($associationName, $data);
        }
        if ($this->keepDirty) {
            $factory->keepDirty();
        }

        // Extract the first Association in the string
        $associationNameToken = strtok($associationName, '.');
        if ($associationNameToken !== false) {
            $associationName = $associationNameToken;
        }

        // Remove the brackets in the association
        $associationNameAfterBrackets = $this->getAssociationBuilder()->removeBrackets($associationName);
        if ($associationNameAfterBrackets !== null) {
            $associationName = $associationNameAfterBrackets;
        }

        $isToOne = $this->getAssociationBuilder()->processToOneAssociation($associationName, $factory);
        $this->getDataCompiler()->collectAssociation($associationName, $factory, $isToOne);

        $this->getAssociationBuilder()->addAssociation($associationName, $factory);

        return $this;
    }

    /**
     * Unset a previously associated factory
     * Useful to bypass associations set in setDefaultTemplate
     *
     * @param string $association Association name
     *
     * @return $this
     */
    public function without(string $association)
    {
        $this->getDataCompiler()->dropAssociation($association);
        $this->getAssociationBuilder()->dropAssociation($association);

        return $this;
    }

    /**
     * @internal Not for normal use, only used for testing.
     *
     * @param array<string, mixed> $data Data to merge
     *
     * @return $this
     */
    public function mergeAssociated(array $data)
    {
        $this->getAssociationBuilder()->addManualAssociations($data);

        return $this;
    }

    /**
     * Per default setters defined in entities are applied.
     * Here the user may define a list of fields for which setters should be ignored
     *
     * @param array<string>|string $skippedSetters Field or list of fields for which setters ought to be skipped
     * @param bool $merge Merge the first argument with the setters already skipped. False by default.
     *
     * @return $this
     */
    public function skipSetterFor(array|string $skippedSetters, bool $merge = false)
    {
        $skippedSetters = (array)$skippedSetters;
        if ($merge) {
            $skippedSetters = array_unique(array_merge($this->skippedSetters, $skippedSetters));
        }
        $this->skippedSetters = $skippedSetters;

        return $this;
    }

    /**
     * Query the factory's related table without before find.
     *
     * @see \Cake\ORM\Query\SelectQuery::find()
     *
     * @param string $type the type of query to perform
     * @param mixed ...$options Options passed to the finder
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface> The query builder
     */
    public static function find(string $type = 'all', mixed ...$options): SelectQuery
    {
        return (new static())->getTable()->find($type, ...$options);
    }

    /**
     * Get from primary key the factory's related table entries, without before find.
     *
     * @see Table::get()
     *
     * @param mixed $primaryKey primary key value to find
     * @param array<string, mixed>|string $finder The finder to use. Passing an options array is deprecated.
     * @param \Psr\SimpleCache\CacheInterface|string|null $cache The cache config to use.
     *   Defaults to `null`, i.e. no caching.
     * @param \Closure|string|null $cacheKey The cache key to use. If not provided
     *   one will be autogenerated if `$cache` is not null.
     * @param mixed ...$args Arguments that query options or finder specific parameters.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public static function get(
        mixed $primaryKey,
        array|string $finder = 'all',
        CacheInterface|string|null $cache = null,
        Closure|string|null $cacheKey = null,
        mixed ...$args,
    ): EntityInterface {
        // Handle backward compatibility for options array
        if (is_array($finder)) {
            $options = $finder;
            $finder = 'all';

            // Convert common options to named parameters
            $table = (new static())->getTable();

            // Extract contain option if present
            if (isset($options['contain'])) {
                // Use named parameters for contain
                if (!$args) {
                    return $table->get($primaryKey, finder: $finder, contain: $options['contain'], cache: $cache, cacheKey: $cacheKey);
                }

                // If there are additional args, we need to use the old style
                return $table->get($primaryKey, $finder, $cache, $cacheKey, ...$options, ...$args);
            }

            // For other options, pass them through args (this will still trigger deprecation)
            return $table->get($primaryKey, $finder, $cache, $cacheKey, ...$options, ...$args);
        }

        // Use named parameters for cleaner calls
        if (!$args) {
            return (new static())->getTable()->get($primaryKey, finder: $finder, cache: $cache, cacheKey: $cacheKey);
        }

        return (new static())->getTable()->get($primaryKey, $finder, $cache, $cacheKey, ...$args);
    }

    /**
     * Count the factory's related table entries without before find.
     *
     * @see Query::count()
     *
     * @return int
     */
    public static function count(): int
    {
        return self::find()->count();
    }

    /**
     * Count the factory's related table entries without before find.
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array<string, mixed>|string|null $conditions The conditions to filter on.
     *
     * @return \Cake\Datasource\EntityInterface|array<string, mixed> The first result from the ResultSet.
     */
    public static function firstOrFail(
        ExpressionInterface|Closure|array|string|null $conditions = null,
    ): EntityInterface|array {
        return self::find()->where($conditions)->firstOrFail();
    }
}
