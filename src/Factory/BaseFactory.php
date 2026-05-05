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
use Cake\Datasource\EntityInterface;
use Cake\Event\EventManagerInterface;
use Cake\I18n\I18n;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function array_merge;
use function is_array;

/**
 * Class BaseFactory
 *
 * Subclasses should declare the entity type via PHPStan generics so that
 * `build()`, `buildMany()`, `save()` and `saveMany()` resolve to the concrete entity class:
 *
 * ```
 * /**
 *  * @extends BaseFactory<\App\Model\Entity\Article>
 *  *\/
 * class ArticleFactory extends BaseFactory { ... }
 * ```
 *
 * @template TEntity of \Cake\Datasource\EntityInterface
 *
 * @package CakephpFixtureFactories\Factory
 */
abstract class BaseFactory
{
    /**
     * Shared default generator used by all factory instances.
     *
     * @var \CakephpFixtureFactories\Generator\GeneratorInterface|null
     */
    private static ?GeneratorInterface $defaultGenerator = null;

    /**
     * Per-instance generator override.
     * Only used when `FixtureFactories.instanceLevelGenerator` is enabled.
     *
     * @var \CakephpFixtureFactories\Generator\GeneratorInterface|null
     */
    private ?GeneratorInterface $instanceGenerator = null;

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
        $this->dataCompiler = $this->buildDataCompiler();
        $this->associationBuilder = new AssociationBuilder($this);
        $this->eventCompiler = $this->buildEventCollector();
    }

    /**
     * Build the data compiler used by this factory.
     *
     * Override in a subclass to swap in a custom DataCompiler implementation
     * (e.g. a subclass with project-specific compilation behavior).
     *
     * @return \CakephpFixtureFactories\Factory\DataCompiler
     */
    protected function buildDataCompiler(): DataCompiler
    {
        return new DataCompiler($this);
    }

    /**
     * Build the event collector used by this factory.
     *
     * Override in a subclass to swap in a custom EventCollector implementation
     * (e.g. a subclass that wires in a project-specific event manager).
     *
     * @return \CakephpFixtureFactories\Factory\EventCollector
     */
    protected function buildEventCollector(): EventCollector
    {
        return new EventCollector($this->getRootTableRegistryName());
    }

    /**
     * Table Registry the factory is building entities from
     *
     * @return string
     */
    abstract protected function getRootTableRegistryName(): string;

    /**
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator Generator
     *
     * @return array<string, mixed>
     */
    abstract public function definition(GeneratorInterface $generator): array;

    /**
     * @param mixed $makeParameter Injected data
     *
     * @throws \InvalidArgumentException
     *
     * @return static
     */
    public static function new(mixed $makeParameter = []): self
    {
        if (is_numeric($makeParameter)) {
            $factory = self::makeFromNonCallable();
            $times = (int)$makeParameter;
        } elseif ($makeParameter === null) {
            $factory = self::makeFromNonCallable();
            $times = 1;
        } elseif (is_array($makeParameter) || $makeParameter instanceof EntityInterface || is_string($makeParameter)) {
            $factory = self::makeFromNonCallable($makeParameter);
            $times = 1;
        } elseif (is_callable($makeParameter)) {
            $factory = self::makeFromCallable($makeParameter);
            $times = 1;
        } else {
            throw new InvalidArgumentException('
                ::new only accepts an array, an integer, an EntityInterface, a string or a callable as first parameter.
            ');
        }

        $factory->setUp($factory, (int)$times);

        return $factory;
    }

    /**
     * Create a factory from an existing entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Injected entity.
     *
     * @return static
     */
    public static function from(EntityInterface $entity): self
    {
        return static::new($entity);
    }

    /**
     * Collect the number of entities to be created
     * Apply the default template in the factory
     *
     * @param \CakephpFixtureFactories\Factory\BaseFactory<TEntity> $factory Factory
     * @param int $times Number of entities created
     *
     * @return void
     */
    protected function setUp(BaseFactory $factory, int $times): void
    {
        $factory->initialize();
        $factory = $factory->configure();
        $factory->times = $times;
        $factory->setDefaultData(fn (GeneratorInterface $generator): array => $factory->definition($generator));
        $factory->getDataCompiler()->collectAssociationsFromDefaultTemplate();
        $this->times = $factory->times;
        $this->keepDirty = $factory->keepDirty;
        $this->dataCompiler = $factory->dataCompiler;
        $this->associationBuilder = $factory->associationBuilder;
        $this->eventCompiler = $factory->eventCompiler;
        $this->marshallerOptions = $factory->marshallerOptions;
        $this->saveOptions = $factory->saveOptions;
        $this->uniqueProperties = $factory->uniqueProperties;
        $this->skippedSetters = $factory->skippedSetters;
        $this->instanceGenerator = $factory->instanceGenerator;
        $this->dataCompiler->setFactory($this);
        $this->associationBuilder->setFactory($this);
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
     * Configure immutable defaults such as associations or listeners.
     *
     * @return static
     */
    protected function configure(): static
    {
        return $this;
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
     * Returns the per-instance generator if set (when `FixtureFactories.instanceLevelGenerator`
     * is enabled and `setGenerator()` was called), otherwise falls back to the shared default.
     *
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    public function getGenerator(): GeneratorInterface
    {
        if ($this->instanceGenerator !== null) {
            return $this->instanceGenerator;
        }

        if (self::$defaultGenerator === null) {
            $locale = I18n::getLocale();
            self::$defaultGenerator = CakeGeneratorFactory::create($locale);
            self::$defaultGenerator->seed(static::getGeneratorSeed());
        }

        return self::$defaultGenerator;
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
     * Set the generator type to use.
     *
     * When `FixtureFactories.instanceLevelGenerator` is enabled, this only affects
     * the current factory instance. Otherwise it sets the global default for all factories.
     *
     * @param string $type The generator type ('faker' or 'dummy')
     * @param string|null $locale Optional locale override
     *
     * @return static
     */
    public function setGenerator(string $type, ?string $locale = null): static
    {
        $factory = clone $this;
        $locale = $locale ?? I18n::getLocale();
        $generator = CakeGeneratorFactory::create($locale, $type);
        $generator->seed(static::getGeneratorSeed());

        if (Configure::read('FixtureFactories.instanceLevelGenerator', false)) {
            $factory->instanceGenerator = $generator;
        } else {
            self::$defaultGenerator = $generator;
        }

        return $factory;
    }

    /**
     * Set the default generator for all factory instances.
     *
     * Unlike `setGenerator()`, this always sets the global default regardless of
     * the `instanceLevelGenerator` configuration.
     *
     * @param string $type The generator type ('faker' or 'dummy')
     * @param string|null $locale Optional locale override
     *
     * @return void
     */
    public static function setDefaultGenerator(string $type, ?string $locale = null): void
    {
        $locale = $locale ?? I18n::getLocale();
        self::$defaultGenerator = CakeGeneratorFactory::create($locale, $type);
        self::$defaultGenerator->seed(static::getGeneratorSeed());
    }

    /**
     * Reset the default generator.
     *
     * Called during test teardown to ensure a fresh generator is created
     * for the next test, respecting the current locale.
     *
     * @return void
     */
    public static function resetDefaultGenerator(): void
    {
        self::$defaultGenerator = null;
    }

    /**
     * Get the configured generator seed.
     *
     * @return int
     */
    protected static function getGeneratorSeed(): int
    {
        return (int)Configure::read('FixtureFactories.seed', 1234);
    }

    /**
     * Produce one entity from the present factory.
     *
     * Use this when the factory was created via `new()`, `new([...singleRow])`,
     * `new($entity)` or `from($entity)` — i.e. exactly one entity will be
     * produced. For multi-entity factories use `buildMany()` instead.
     *
     * @throws \RuntimeException if the factory is configured to produce more than one entity.
     *
     * @return TEntity
     */
    public function build(): EntityInterface
    {
        $entities = $this->toArray();
        $count = count($entities);
        if ($count !== 1) {
            throw new RuntimeException(sprintf(
                '%s::build() expected to build exactly 1 entity, but %d were produced. Use buildMany() for factories that produce multiple entities.',
                static::class,
                $count,
            ));
        }

        return $entities[0];
    }

    /**
     * Produce a set of entities from the present factory.
     *
     * Works for any factory shape (single or multiple); always returns an
     * array, so it is the right choice when callers iterate or assert on
     * counts regardless of how the factory was configured.
     *
     * @return array<TEntity>
     */
    public function buildMany(): array
    {
        return $this->toArray();
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return TEntity
     */
    public function getEntity(): EntityInterface
    {
        return $this->toArray()[0];
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return array<TEntity>
     */
    public function getEntities(): array
    {
        return $this->buildMany();
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return \Cake\ORM\ResultSet<int, TEntity>
     */
    public function getResultSet(): ResultSet
    {
        return new ResultSet($this->buildMany());
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return \Cake\ORM\ResultSet<int, TEntity>
     */
    public function getPersistedResultSet(): ResultSet
    {
        return new ResultSet($this->saveMany());
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
     * Persist a single entity and return it.
     *
     * Use this when the factory was created via `new()`, `new([...singleRow])`,
     * `new($entity)` or `from($entity)` — i.e. exactly one entity will be
     * produced. For multi-entity factories use `saveMany()` instead.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $data Last-mile override data
     *
     * @throws \RuntimeException if the factory is configured to produce more than one entity.
     *
     * @return TEntity
     */
    public function save(array|EntityInterface $data = []): EntityInterface
    {
        $factory = $data ? $this->state($data) : $this;
        $entities = $factory->doPersist();
        $count = count($entities);
        if ($count !== 1) {
            throw new RuntimeException(sprintf(
                '%s::save() expected to persist exactly 1 entity, but %d were produced. '
                . 'Use saveMany() for factories that produce multiple entities.',
                static::class,
                $count,
            ));
        }

        return $entities[0];
    }

    /**
     * Persist all configured entities and return them as an array.
     *
     * Works for any factory shape (single or multiple); always returns an
     * array, so it is the right choice when callers iterate or assert on
     * counts regardless of how the factory was configured.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $data Last-mile override data
     *
     * @return array<TEntity>
     */
    public function saveMany(array|EntityInterface $data = []): array
    {
        $factory = $data ? $this->state($data) : $this;

        return $factory->doPersist();
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @throws \RuntimeException if the factory is configured to produce more than one entity.
     *
     * @return TEntity
     */
    public function persistEntity(): EntityInterface
    {
        $entities = $this->doPersist();
        $count = count($entities);
        if ($count !== 1) {
            throw new RuntimeException(sprintf(
                '%s::persistEntity() expected to persist exactly 1 entity, but %d were produced. Use persistEntities() for factories that produce multiple entities.',
                static::class,
                $count,
            ));
        }

        return $entities[0];
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return array<TEntity>
     */
    public function persistEntities(): array
    {
        return $this->saveMany();
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return TEntity|array<TEntity>
     */
    public function persist(): EntityInterface|array
    {
        $entities = $this->doPersist();

        return count($entities) === 1 ? $entities[0] : $entities;
    }

    /**
     * Persist the configured entities and return them as a normalized array.
     *
     * @throws \CakephpFixtureFactories\Error\PersistenceException if the entity/entities could not be saved.
     *
     * @return array<TEntity>
     */
    private function doPersist(): array
    {
        $this->getDataCompiler()->startPersistMode();
        try {
            $entities = $this->toArray();
        } finally {
            $this->getDataCompiler()->endPersistMode();
        }

        $table = $this->getTable();
        FactoryTableTracker::getInstance()->trackTable($table);

        $strategy = FactoryTransactionStrategy::getActiveInstance();
        if ($strategy !== null) {
            $strategy->ensureTransaction($table->getConnection());
        }

        try {
            if (count($entities) === 1) {
                $saved = [$table->saveOrFail($entities[0], $this->getSaveOptions())];
            } else {
                $result = $table->saveManyOrFail($entities, $this->getSaveOptions());
                $saved = is_array($result) ? array_values($result) : iterator_to_array($result, false);
            }
        } catch (Throwable $exception) {
            $factory = static::class;
            $message = $exception->getMessage();

            throw new PersistenceException("Error in Factory `$factory`.\n Message: $message \n");
        }

        $this->finalizePersistedEntities($saved, $table);

        return $saved;
    }

    /**
     * Mirror the deferred entity bookkeeping from `Cake\ORM\Table::save()`.
     *
     * Since CakePHP 5.4, `$entity->clean()`, `$entity->setNew(false)` and
     * `$entity->setSource()` are deferred via `Connection::afterCommit()`
     * whenever the save runs inside an outer transaction. The transaction
     * strategy used here only ever rolls back at teardown, so those
     * callbacks are discarded and persisted entities still report
     * `isNew() === true` to the test — which silently breaks any code
     * that calls `Table::delete($entity)` (it short-circuits on new
     * entities) or otherwise inspects post-save entity state.
     *
     * Applying the same bookkeeping here restores the synchronous
     * pre-5.4 behavior that tests have relied on. CakePHP <= 5.3 is
     * unaffected — it already ran this synchronously inside `save()`,
     * so this is a harmless second application (the entity is already
     * clean and not new by the time we get here).
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities Saved entities returned by the table.
     * @param \Cake\ORM\Table $table The table the save was performed on.
     */
    private function finalizePersistedEntities(array $entities, Table $table): void
    {
        if (!$table->getConnection()->inTransaction()) {
            return;
        }

        $alias = $table->getRegistryAlias();
        foreach ($entities as $entity) {
            $entity->clean();
            $entity->setNew(false);
            $entity->setSource($alias);
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
     * @param callable(\CakephpFixtureFactories\Factory\BaseFactory<TEntity>, \CakephpFixtureFactories\Generator\GeneratorInterface): array<string, mixed>|\Cake\Datasource\EntityInterface|array<string, mixed> $data Data to inject
     *
     * @return static
     */
    public function state(array|callable|EntityInterface $data): static
    {
        $factory = clone $this;
        if (is_callable($data)) {
            $factory->getDataCompiler()->collectArrayFromCallable($data);

            return $factory;
        }
        if ($data instanceof EntityInterface) {
            $data = $data->toArray();
        }
        $factory->getDataCompiler()->collectFromPatch($data);

        return $factory;
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @param \Cake\Datasource\EntityInterface|array<string, mixed> $data Data to inject
     *
     * @return static
     */
    public function patchData(array|EntityInterface $data): static
    {
        return $this->state($data);
    }

    /**
     * Sets the value for a single field
     *
     * @param string $field to set
     * @param mixed $value to assign
     *
     * @return static
     */
    public function setField(string $field, mixed $value): static
    {
        return $this->state([$field => $value]);
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
     * @return static
     */
    public function count(int $times): static
    {
        $factory = clone $this;
        $factory->times = $times;

        return $factory;
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return static
     */
    public function setTimes(int $times): static
    {
        return $this->count($times);
    }

    /**
     * Keep the resulting entities dirty for manual saves.
     *
     * @param bool $keepDirty Whether to keep entities dirty.
     *
     * @return static
     */
    public function keepDirty(bool $keepDirty = true): static
    {
        $factory = clone $this;
        $factory->keepDirty = $keepDirty;
        if ($keepDirty) {
            foreach ($factory->getAssociationBuilder()->getAssociations() as $associationName => $associationFactory) {
                $dirtyAssociationFactory = $associationFactory->keepDirty();
                $factory->getAssociationBuilder()->addAssociation($associationName, $dirtyAssociationFactory);
                $factory->getDataCompiler()->replaceAssociationFactory($associationName, $dirtyAssociationFactory);
            }
        }

        return $factory;
    }

    /**
     * @param array<string>|string $activeBehaviors Behaviors listened to by the factory
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException on argument passed error
     *
     * @return static
     */
    public function listeningToBehaviors(array|string $activeBehaviors): static
    {
        $factory = clone $this;
        $activeBehaviors = (array)$activeBehaviors;
        if (!$activeBehaviors) {
            throw new FixtureFactoryException('Expecting a non empty string or an array of string.');
        }
        $factory->getEventCompiler()->listeningToBehaviors($activeBehaviors);

        return $factory;
    }

    /**
     * Set the database connection to use for the table.
     *
     * @param string $connectionName Name of the database connection
     *
     * @return static
     */
    public function setConnection(string $connectionName): static
    {
        $factory = clone $this;
        $factory->getEventCompiler()->setConnection($connectionName);

        return $factory;
    }

    /**
     * Set a custom event manager for the factory's table.
     *
     * @param \Cake\Event\EventManagerInterface $eventManager The event manager instance
     *
     * @return static
     */
    public function setEventManager(EventManagerInterface $eventManager): static
    {
        $factory = clone $this;
        $factory->getEventCompiler()->setEventManager($eventManager);

        return $factory;
    }

    /**
     * @param array<string>|string $activeModelEvents Model events listened to by the factory
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException on argument passed error
     *
     * @return static
     */
    public function listeningToModelEvents(array|string $activeModelEvents): static
    {
        $factory = clone $this;
        $activeModelEvents = (array)$activeModelEvents;
        if (!$activeModelEvents) {
            throw new FixtureFactoryException('Expecting a non empty string or an array of string.');
        }
        $factory->getEventCompiler()->listeningToModelEvents($activeModelEvents);

        return $factory;
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
     * @return static
     */
    public function setPrimaryKeyOffset(int|string|array $primaryKeyOffset): static
    {
        $factory = clone $this;
        $factory->getDataCompiler()->setPrimaryKeyOffset($primaryKeyOffset);

        return $factory;
    }

    /**
     * Will not set primary key when saving the entity, instead SQL engine can handle that.
     *
     * @return static
     */
    public function disablePrimaryKeyOffset(): static
    {
        $factory = clone $this;
        $factory->getDataCompiler()->disablePrimaryKeyOffset();

        return $factory;
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
     * @return static
     */
    public function setUniqueProperties(array|string|null $fields): static
    {
        $factory = clone $this;
        $factory->uniqueProperties = (array)$fields;

        return $factory;
    }

    /**
     * Populate the entity factored
     *
     * @param callable $fn Callable delivering injected data
     *
     * @return static
     */
    protected function setDefaultData(callable $fn): static
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
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>|\Cake\Datasource\EntityInterface|callable|array<string, mixed>|string|int $data Injected data
     *
     * @return static
     */
    public function with(string $associationName, array|int|callable|BaseFactory|EntityInterface|string $data = []): static
    {
        $factory = clone $this;
        $factory->getAssociationBuilder()->getAssociation($associationName);

        if (!str_contains($associationName, '.') && $data instanceof BaseFactory) {
            $associatedFactory = $data;
        } else {
            $associatedFactory = $factory->getAssociationBuilder()->getAssociatedFactory($associationName, $data);
        }
        if ($factory->keepDirty) {
            $associatedFactory = $associatedFactory->keepDirty();
        }

        // Extract the first Association in the string
        $associationNameToken = strtok($associationName, '.');
        if ($associationNameToken !== false) {
            $associationName = $associationNameToken;
        }

        // Remove the brackets in the association
        $associationName = $factory->getAssociationBuilder()->removeBrackets($associationName);

        $associatedFactory = $factory->getAssociationBuilder()->prepareAssociationFactory(
            $associationName,
            $associatedFactory,
        );
        $isToOne = $factory->getAssociationBuilder()->associationIsToOne(
            $factory->getAssociationBuilder()->getAssociation($associationName),
        );
        $factory->getDataCompiler()->collectAssociation($associationName, $associatedFactory, $isToOne);

        $factory->getAssociationBuilder()->addAssociation($associationName, $associatedFactory);

        return $factory;
    }

    /**
     * Unset a previously associated factory
     * Useful to bypass associations set in setDefaultTemplate
     *
     * @param string $association Association name
     *
     * @return static
     */
    public function without(string $association): static
    {
        $factory = clone $this;
        $factory->getDataCompiler()->dropAssociation($association);
        $factory->getAssociationBuilder()->dropAssociation($association);

        return $factory;
    }

    /**
     * @internal Not for normal use, only used for testing.
     *
     * @param array<string, mixed> $data Data to merge
     *
     * @return static
     */
    public function mergeAssociated(array $data): static
    {
        $factory = clone $this;
        $factory->getAssociationBuilder()->addManualAssociations($data);

        return $factory;
    }

    /**
     * Per default setters defined in entities are applied.
     * Here the user may define a list of fields for which setters should be ignored
     *
     * @param array<string>|string $skippedSetters Field or list of fields for which setters ought to be skipped
     * @param bool $merge Merge the first argument with the setters already skipped. False by default.
     *
     * @return static
     */
    public function skipSetterFor(array|string $skippedSetters, bool $merge = false): static
    {
        $factory = clone $this;
        $skippedSetters = (array)$skippedSetters;
        if ($merge) {
            $skippedSetters = array_unique(array_merge($factory->skippedSetters, $skippedSetters));
        }
        $factory->skippedSetters = $skippedSetters;

        return $factory;
    }

    /**
     * Query the factory's related table without before find.
     *
     * @see \Cake\ORM\Query\SelectQuery::find()
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface> The query builder
     */
    public static function query(): SelectQuery
    {
        return (new static())->getTable()->find();
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return static
     */
    public static function make(mixed $makeParameter = [], int $times = 1): self
    {
        $factory = static::new($makeParameter);
        if ($times !== 1) {
            $factory = $factory->count($times);
        }

        return $factory;
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return static
     */
    public static function makeMany(int $times): self
    {
        return static::new()->count($times);
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return static
     */
    public static function makeWith(callable $fn): self
    {
        return static::new($fn);
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return static
     */
    public static function makeFrom(EntityInterface $entity): self
    {
        return static::from($entity);
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>
     */
    public static function find(string $type = 'all', mixed ...$options): SelectQuery
    {
        return (new static())->getTable()->find($type, ...$options);
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @param mixed $primaryKey Primary key value to find
     * @param array<string, mixed>|string $finder Finder name or options array
     * @param mixed ...$args Additional finder arguments
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public static function get(
        mixed $primaryKey,
        array|string $finder = 'all',
        mixed ...$args,
    ): EntityInterface {
        if (is_array($finder)) {
            $options = $finder;
            $finder = 'all';

            if (!$args && isset($options['contain'])) {
                return (new static())->getTable()->get($primaryKey, finder: $finder, contain: $options['contain']);
            }

            return (new static())->getTable()->get($primaryKey, $finder, ...$options, ...$args);
        }

        return (new static())->getTable()->get($primaryKey, $finder, ...$args);
    }

    /**
     * @deprecated Transitional wrapper for the v2 branch.
     *
     * @return \Cake\Datasource\EntityInterface|array<string, mixed>
     */
    public static function firstOrFail(mixed $conditions = null): EntityInterface|array
    {
        return static::query()->where($conditions)->firstOrFail();
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Associated belongsTo factory
     *
     * @return static
     */
    public function for(BaseFactory $factory): static
    {
        $association = $this->resolveDirectionalAssociation($factory, true);

        return $this->with($association, $factory);
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Associated factory
     * @param array<string, mixed> $pivot Pivot data for belongsToMany joins
     *
     * @return static
     */
    public function has(BaseFactory $factory, array $pivot = []): static
    {
        $association = $this->resolveDirectionalAssociation($factory, false);
        if ($pivot !== []) {
            $factory = $factory->mergeAssociated(['_joinData' => $pivot]);
        }

        return $this->with($association, $factory);
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Related factory
     * @param bool $belongsTo Whether to resolve a belongsTo association
     *
     * @throws \RuntimeException if no matching directional association can be found.
     *
     * @return string
     */
    protected function resolveDirectionalAssociation(BaseFactory $factory, bool $belongsTo): string
    {
        $sourceTable = $this->getTable();
        $targetRegistryAlias = $factory->getRootTableRegistryName();
        foreach ($sourceTable->associations() as $association) {
            $associationTargetsFactory = $association->getClassName() === $targetRegistryAlias
                || $association->getTarget()->getRegistryAlias() === $targetRegistryAlias
                || $association->getName() === $targetRegistryAlias;
            if (!$associationTargetsFactory) {
                continue;
            }
            if ($belongsTo && $association instanceof BelongsTo) {
                return $association->getName();
            }
            if (!$belongsTo && !$association instanceof BelongsTo) {
                return $association->getName();
            }
        }

        throw new RuntimeException(sprintf(
            'Unable to resolve %s association from `%s` to `%s`.',
            $belongsTo ? 'belongsTo' : 'has*',
            $sourceTable->getRegistryAlias(),
            $targetRegistryAlias,
        ));
    }

    public function __clone(): void
    {
        $this->dataCompiler = clone $this->dataCompiler;
        $this->dataCompiler->setFactory($this);
        $this->associationBuilder = clone $this->associationBuilder;
        $this->associationBuilder->setFactory($this);
        $this->eventCompiler = clone $this->eventCompiler;
    }
}
