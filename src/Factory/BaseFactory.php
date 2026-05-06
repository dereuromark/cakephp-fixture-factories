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

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventManagerInterface;
use Cake\I18n\I18n;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Query\SelectQuery;
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
     * Explicit global generator override shared by all factory instances.
     *
     * @var \CakephpFixtureFactories\Generator\GeneratorInterface|null
     */
    private static ?GeneratorInterface $defaultGenerator = null;

    /**
     * Lazily-created default generators keyed by factory class / locale / seed / configured type.
     *
     * @var array<string, \CakephpFixtureFactories\Generator\GeneratorInterface>
     */
    private static array $defaultGenerators = [];

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
     * @var array<int, callable(\Cake\Datasource\EntityInterface, int, static): (\Cake\Datasource\EntityInterface|void)>
     */
    private array $afterBuildCallbacks = [];

    /**
     * @var array<int, callable(\Cake\Datasource\EntityInterface, int, static): (\Cake\Datasource\EntityInterface|void)>
     */
    private array $afterSaveCallbacks = [];

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
     * @param int $times Number of entities to produce. Prefer `->count()` in userland.
     *
     * @throws \InvalidArgumentException
     *
     * @return static
     */
    public static function new(mixed $makeParameter = [], int $times = 1): self
    {
        if (is_int($makeParameter) || is_float($makeParameter)) {
            $factory = self::makeFromNonCallable();
            $times = (int)$makeParameter;
        } elseif ($makeParameter === null) {
            $factory = self::makeFromNonCallable();
        } elseif (is_array($makeParameter) || $makeParameter instanceof EntityInterface || is_string($makeParameter)) {
            $factory = self::makeFromNonCallable($makeParameter);
        } elseif (is_callable($makeParameter)) {
            $factory = self::makeFromCallable($makeParameter);
        } else {
            throw new InvalidArgumentException(
                '::new only accepts an array, an integer, an EntityInterface, a string or a callable as first parameter.',
            );
        }

        $times = (int)$times;
        if ($times < 1) {
            throw new InvalidArgumentException(sprintf(
                '::new() expects a positive count, got `%d`. Use ->count() with a positive integer to change the number of entities produced.',
                $times,
            ));
        }

        $factory->setUp($factory, $times);

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
        $this->afterBuildCallbacks = $factory->afterBuildCallbacks;
        $this->afterSaveCallbacks = $factory->afterSaveCallbacks;
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

        if (self::$defaultGenerator !== null) {
            return self::$defaultGenerator;
        }

        $cacheKey = implode('|', [
            static::class,
            I18n::getLocale(),
            (string)static::getGeneratorSeed(),
            (string)Configure::read('FixtureFactories.generatorType', ''),
        ]);
        if (!isset(self::$defaultGenerators[$cacheKey])) {
            $locale = I18n::getLocale();
            $generator = clone CakeGeneratorFactory::create($locale);
            $generator->seed(static::getGeneratorSeed());
            self::$defaultGenerators[$cacheKey] = $generator;
        }

        return self::$defaultGenerators[$cacheKey];
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
        $generator = clone CakeGeneratorFactory::create($locale, $type);
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
        self::$defaultGenerator = clone CakeGeneratorFactory::create($locale, $type);
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
        self::$defaultGenerators = [];
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
     * Override marshaller options with immutable semantics.
     *
     * By default options are merged on top of the existing defaults. Pass
     * `$merge = false` to replace the entire option set.
     *
     * @param array<string, mixed> $marshallerOptions Marshaller options
     * @param bool $merge Whether to merge with existing options
     *
     * @return static
     */
    public function setMarshallerOptions(array $marshallerOptions, bool $merge = true): static
    {
        $factory = clone $this;
        $factory->marshallerOptions = $merge
            ? array_merge($factory->marshallerOptions, $marshallerOptions)
            : $marshallerOptions;

        return $factory;
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
            $dataCompiler->setSequenceIndex($i);
            $compiledData = $dataCompiler->getCompiledTemplateData();
            if (is_array($compiledData)) {
                $entities = array_merge($entities, $compiledData);
            } else {
                $entities[] = $compiledData;
            }
        }
        UniquenessJanitor::sanitizeEntityArray($this, $entities);
        $entities = $this->applyCallbacks($entities, $this->afterBuildCallbacks);

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

            throw new PersistenceException(
                "Error in Factory `$factory`.\n Message: $message \n",
                (int)$exception->getCode(),
                $exception,
            );
        }

        $this->finalizePersistedEntities($saved, $table);
        $this->replayAssociatedAfterSaveEvents($saved, $this);
        $saved = $this->applyCallbacks($saved, $this->afterSaveCallbacks);

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

        $alias = $table->getAlias();
        foreach ($entities as $entity) {
            $entity->clean();
            $entity->setNew(false);
            $entity->setSource($alias);
        }
    }

    /**
     * Replay child-factory Model.afterSave listeners for saved associated entities.
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory
     *
     * @return void
     */
    private function replayAssociatedAfterSaveEvents(array $entities, BaseFactory $factory): void
    {
        foreach ($factory->getAssociationBuilder()->getAssociations() as $associationName => $associationFactory) {
            $property = $factory->getAssociationBuilder()->getAssociation($associationName)->getProperty();
            $associatedEntities = [];
            foreach ($entities as $entity) {
                $value = $entity->get($property);
                if ($value instanceof EntityInterface) {
                    $associatedEntities[] = $value;

                    continue;
                }
                if (!is_array($value)) {
                    continue;
                }
                foreach ($value as $item) {
                    if ($item instanceof EntityInterface) {
                        $associatedEntities[] = $item;
                    }
                }
            }
            if ($associatedEntities === []) {
                continue;
            }

            $options = new ArrayObject(['associated' => true, 'atomic' => false]);
            foreach ($associatedEntities as $entity) {
                $associationFactory->getTable()->dispatchEvent('Model.afterSave', compact('entity', 'options'));
            }
            $this->replayAssociatedAfterSaveEvents($associatedEntities, $associationFactory);
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
     * Override save options with immutable semantics.
     *
     * By default options are merged on top of the existing defaults. Pass
     * `$merge = false` to replace the entire option set.
     *
     * @param array<string, mixed> $saveOptions Save options
     * @param bool $merge Whether to merge with existing options
     *
     * @return static
     */
    public function setSaveOptions(array $saveOptions, bool $merge = true): static
    {
        $factory = clone $this;
        $factory->saveOptions = $merge
            ? array_merge($factory->saveOptions, $saveOptions)
            : $saveOptions;

        return $factory;
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
     * Cycle through the provided states while building multiple entities.
     *
     * Sequence data is applied after `definition()` and injected instantiation
     * data, but before a later plain `state()` override.
     *
     * @param \Cake\Datasource\EntityInterface|callable|array<string, mixed> ...$states Sequence states
     *
     * @throws \InvalidArgumentException
     *
     * @return static
     */
    public function sequence(array|callable|EntityInterface ...$states): static
    {
        if ($states === []) {
            throw new InvalidArgumentException('sequence() expects at least one array, entity or callable state.');
        }

        $factory = clone $this;
        /** @var array<int, \Cake\Datasource\EntityInterface|callable|array<string, mixed>> $states */
        $factory->getDataCompiler()->collectSequence($states);

        return $factory;
    }

    /**
     * Register a callback to run on each built entity before `build*()` returns
     * and before `save*()` persists the entity.
     *
     * @param callable(\Cake\Datasource\EntityInterface, int, static): (\Cake\Datasource\EntityInterface|void) $callback Callback
     *
     * @return static
     */
    public function afterBuild(callable $callback): static
    {
        $factory = clone $this;
        $factory->afterBuildCallbacks[] = $callback;

        return $factory;
    }

    /**
     * Register a callback to run on each entity after it has been saved.
     *
     * @param callable(\Cake\Datasource\EntityInterface, int, static): (\Cake\Datasource\EntityInterface|void) $callback Callback
     *
     * @return static
     */
    public function afterSave(callable $callback): static
    {
        $factory = clone $this;
        $factory->afterSaveCallbacks[] = $callback;

        return $factory;
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
     * Expose normalized association marshalling config for advanced use cases.
     *
     * @return array<string, mixed>
     */
    public function getAssociatedFactories(): array
    {
        return $this->getAssociationBuilder()->getAssociated();
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
     * Set the amount of entities generated by the factory.
     *
     * @param int $times Number of entities to create. Must be at least 1.
     *
     * @throws \InvalidArgumentException When $times is less than 1.
     *
     * @return static
     */
    public function count(int $times): static
    {
        if ($times < 1) {
            throw new InvalidArgumentException(sprintf(
                '::count() expects a positive integer, got `%d`.',
                $times,
            ));
        }
        $factory = clone $this;
        $factory->times = $times;

        return $factory;
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
        $factory->getAssociationBuilder()->mapAssociations(
            static fn (BaseFactory $associationFactory): BaseFactory => $associationFactory->keepDirty($keepDirty),
        );
        $factory->getDataCompiler()->mapAssociationFactories(
            static fn (BaseFactory $associationFactory): BaseFactory => $associationFactory->keepDirty($keepDirty),
        );

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
        $association = $factory->getAssociationBuilder()->getAssociation($associationName);
        $isToOne = $factory->getAssociationBuilder()->associationIsToOne($association);
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
     * Access the factory's related table directly.
     *
     * Useful for table-level operations like `Table::get()`.
     *
     * @return \Cake\ORM\Table
     */
    public static function table(): Table
    {
        return (new static())->getTable();
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
        $matches = [];
        foreach ($sourceTable->associations() as $association) {
            $associationTargetsFactory = $association->getClassName() === $targetRegistryAlias
                || $association->getTarget()->getRegistryAlias() === $targetRegistryAlias
                || $association->getName() === $targetRegistryAlias;
            if (!$associationTargetsFactory) {
                continue;
            }
            if ($belongsTo && $association instanceof BelongsTo) {
                $matches[] = $association->getName();
            }
            if (!$belongsTo && !$association instanceof BelongsTo) {
                $matches[] = $association->getName();
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($matches !== []) {
            throw new RuntimeException(sprintf(
                'Ambiguous %s association from `%s` to `%s`: %s. Use with() to select the explicit association name.',
                $belongsTo ? 'belongsTo' : 'has*',
                $sourceTable->getRegistryAlias(),
                $targetRegistryAlias,
                implode(', ', $matches),
            ));
        }

        throw new RuntimeException(sprintf(
            'Unable to resolve %s association from `%s` to `%s`.',
            $belongsTo ? 'belongsTo' : 'has*',
            $sourceTable->getRegistryAlias(),
            $targetRegistryAlias,
        ));
    }

    /**
     * @param array<\Cake\Datasource\EntityInterface> $entities Entities
     * @param array<int, callable(\Cake\Datasource\EntityInterface, int, static): (\Cake\Datasource\EntityInterface|void)> $callbacks Callbacks
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    private function applyCallbacks(array $entities, array $callbacks): array
    {
        if ($callbacks === []) {
            return $entities;
        }

        foreach ($entities as $index => $entity) {
            foreach ($callbacks as $callback) {
                $result = $callback($entity, $index, $this);
                if ($result instanceof EntityInterface) {
                    $entity = $result;
                }
            }
            $entities[$index] = $entity;
        }

        return $entities;
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
