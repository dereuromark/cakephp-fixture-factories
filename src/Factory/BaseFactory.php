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

/**
 * Class BaseFactory
 *
 * Subclasses should declare the entity type via PHPStan generics so that
 * `build()`, `buildMany()`, `save()` and `saveMany()` resolve to the concrete entity class.
 *
 * @template TEntity of \Cake\Datasource\EntityInterface
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
     * Internal bootstrap mode for static read helpers.
     * During this phase configure()/initialize() may set connection or listener
     * defaults, but association-building fluent calls should be ignored.
     *
     * @var bool
     */
    private bool $readBootstrapMode = false;

    /**
     * Already-built entities that should be reused everywhere a belongsTo
     * association in this factory's build graph targets the same source table.
     *
     * Keyed by `EntityInterface::getSource()`. Shared (recursively propagated)
     * to child factories at compile time.
     *
     * @var array<string, \Cake\Datasource\EntityInterface>
     */
    private array $recycledEntities = [];

    /**
     * @var array<int, callable(TEntity, int, static): (TEntity|void)>
     */
    private array $afterBuildCallbacks = [];

    /**
     * @var array<int, callable(TEntity, int, static): (TEntity|void)>
     */
    private array $afterSaveCallbacks = [];

    /**
     * The data compiler gathers the data from the
     * default template, the injection and patched data
     * and compiles it to produce the data feeding the
     * entities of the Factory
     *
     * @var \CakephpFixtureFactories\Factory\DataCompiler<TEntity>
     */
    private DataCompiler $dataCompiler;

    /**
     * Helper to check and build data in associations
     *
     * @var \CakephpFixtureFactories\Factory\AssociationBuilder<TEntity>
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
     * @return \CakephpFixtureFactories\Factory\DataCompiler<TEntity>
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
    public static function new(mixed $makeParameter = [], int $times = 1): static
    {
        if (is_int($makeParameter)) {
            $factory = self::makeFromNonCallable();
            $times = $makeParameter;
        } elseif (is_float($makeParameter)) {
            throw new InvalidArgumentException(
                '::new() only accepts an integer count as first parameter. Floats are not allowed; use ->count() with a positive integer.',
            );
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

        return $factory->setUp($times);
    }

    /**
     * Create a factory from an existing entity.
     *
     * @param TEntity $entity Injected entity.
     *
     * @return static
     */
    public static function from(EntityInterface $entity): static
    {
        return static::new($entity);
    }

    /**
     * Collect the number of entities to be created
     * Apply the default template in the factory
     *
     * @param int $times Number of entities created
     *
     * @throws \RuntimeException When $times > 1 is combined with a factory
     *   instantiated from an existing entity.
     *
     * @return static
     */
    protected function setUp(int $times): static
    {
        $this->initialize();
        $factory = $this->configure();
        if ($times > 1 && $factory->getDataCompiler()->isInstantiatedFromEntity()) {
            throw new RuntimeException(
                self::entityWithMultipleCountMessage(static::class, $times),
            );
        }
        $factory->times = $times;
        $definitionFactory = $factory;
        $factory = $factory->setDefaultData(
            fn (GeneratorInterface $generator): array => $definitionFactory->definition($generator),
        );
        $factory->getDataCompiler()->collectAssociationsFromDefaultTemplate();

        return $factory;
    }

    /**
     * Compose the message used by the entity-with-count guards in setUp() and
     * count(). Both error sites point users at the same workaround so the fix
     * is discoverable from either entry point.
     */
    private static function entityWithMultipleCountMessage(string $factory, int $times): string
    {
        return sprintf(
            '%s cannot produce %d entities from a single injected entity — `new($entity)` and `from($entity)` wrap exactly one existing entity. '
            . 'To produce N entities seeded from an existing one, extract its data and pass it through `new()` instead: '
            . '%s::new($entity->toArray())->count(%d).',
            $factory,
            $times,
            $factory,
            $times,
        );
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
    private static function makeFromNonCallable(EntityInterface|array|string $data = []): static
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
    private static function makeFromCallable(callable $fn): static
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

        $locale = self::resolveLocale();
        $cacheKey = implode('|', [
            static::class,
            $locale,
            (string)static::getGeneratorSeed(),
            (string)Configure::read('FixtureFactories.generatorType', ''),
        ]);
        if (!isset(self::$defaultGenerators[$cacheKey])) {
            $generator = clone CakeGeneratorFactory::create($locale);
            $generator->seed(static::getGeneratorSeed());
            self::$defaultGenerators[$cacheKey] = $generator;
        }

        return self::$defaultGenerators[$cacheKey];
    }

    /**
     * Resolve the locale used by all generator-creation paths.
     *
     * Precedence:
     * 1. explicit `$override` (e.g. from `setGenerator($type, $locale)`),
     * 2. `Configure::read('FixtureFactories.defaultLocale')`,
     * 3. `I18n::getLocale()` as the final fallback.
     *
     * Centralizing this prevents the `defaultLocale` Configure key from being
     * silently bypassed by callers that pre-default to `I18n::getLocale()`.
     *
     * @param string|null $override Caller-supplied locale, if any.
     */
    private static function resolveLocale(?string $override = null): string
    {
        if ($override !== null) {
            return $override;
        }
        $configured = Configure::read('FixtureFactories.defaultLocale');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return I18n::getLocale();
    }

    /**
     * Set the generator type to use.
     *
     * When `FixtureFactories.instanceLevelGenerator` is enabled, this clones the
     * factory and scopes the override to the returned instance. Otherwise it
     * mutates the process-wide default generator and returns `$this` — every
     * other factory in the process is also affected. The fluent return value is
     * preserved in both modes for ergonomics, but is only meaningful (i.e.
     * scoped) in instance mode.
     *
     * @param string $type The generator type ('faker' or 'dummy')
     * @param string|null $locale Optional locale override
     *
     * @return static
     */
    public function setGenerator(string $type, ?string $locale = null): static
    {
        $resolvedLocale = self::resolveLocale($locale);
        $generator = clone CakeGeneratorFactory::create($resolvedLocale, $type);
        $generator->seed(static::getGeneratorSeed());

        if (Configure::read('FixtureFactories.instanceLevelGenerator', true)) {
            $factory = clone $this;
            $factory->instanceGenerator = $generator;

            return $factory;
        }

        self::$defaultGenerator = $generator;

        return $this;
    }

    /**
     * Set the default generator for all factory instances.
     *
     * Unlike `setGenerator()`, this always sets the global default regardless of
     * the `instanceLevelGenerator` configuration.
     *
     * Note: the static slot is shared across every `BaseFactory` subclass in the
     * process. Calls from different subclasses race for it — the last call wins
     * for everyone. Use the `instanceLevelGenerator` flag with `setGenerator()`
     * if you need per-factory generators.
     *
     * @param string $type The generator type ('faker' or 'dummy')
     * @param string|null $locale Optional locale override
     *
     * @return void
     */
    public static function setDefaultGenerator(string $type, ?string $locale = null): void
    {
        $resolvedLocale = self::resolveLocale($locale);
        self::$defaultGenerator = clone CakeGeneratorFactory::create($resolvedLocale, $type);
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
     * @return array<TEntity>
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
     * @param array<string, mixed> $data Last-mile override data
     *
     * @throws \RuntimeException if the factory is configured to produce more than one entity.
     *
     * @return TEntity
     */
    public function save(array $data = []): EntityInterface
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
     * @param array<string, mixed> $data Last-mile override data
     *
     * @return array<TEntity>
     */
    public function saveMany(array $data = []): array
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
        $saved = $this->replayAssociatedAfterSaveEvents($saved, $this);
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
     * @param array<TEntity> $entities Saved entities returned by the table.
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
     * Replay child-factory Model.afterSave listeners and afterSave callbacks
     * for saved associated entities.
     *
     * Cake's cascading save persists child entities as part of the parent's
     * save, but it does not invoke any user-registered `afterSave()` callbacks
     * on child factories (those callbacks live on the child `BaseFactory`
     * instance, never reached by the parent's persist flow). We re-fire both
     * the Cake event (so behaviors run) and the child factory's own
     * `afterSaveCallbacks` here so a chain like
     * `ArticleFactory::new()->with('Author', AuthorFactory::new()->afterSave(...))`
     * behaves the same regardless of nesting depth.
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory
     * @param array<string, bool> $visited Replay guard
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    private function replayAssociatedAfterSaveEvents(array $entities, BaseFactory $factory, array &$visited = []): array
    {
        foreach ($factory->getAssociationBuilder()->getAssociations() as $associationName => $associationFactory) {
            $property = $factory->getAssociationBuilder()->getAssociation($associationName)->getProperty();
            foreach ($entities as $index => $entity) {
                $value = $entity->get($property);
                if ($value instanceof EntityInterface) {
                    $entity->set($property, $this->replayAssociatedAfterSaveForEntity($value, $associationFactory, $visited));
                    $entity->setDirty($property, false);
                    $entities[$index] = $entity;

                    continue;
                }
                if (!is_array($value)) {
                    continue;
                }

                $updated = [];
                foreach ($value as $item) {
                    if ($item instanceof EntityInterface) {
                        $updated[] = $this->replayAssociatedAfterSaveForEntity($item, $associationFactory, $visited);
                    } else {
                        $updated[] = $item;
                    }
                }
                $entity->set($property, $updated);
                $entity->setDirty($property, false);
                $entities[$index] = $entity;
            }
        }

        return $entities;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Associated entity
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $associationFactory Associated factory
     * @param array<string, bool> $visited Replay guard
     *
     * @return \Cake\Datasource\EntityInterface
     */
    private function replayAssociatedAfterSaveForEntity(
        EntityInterface $entity,
        BaseFactory $associationFactory,
        array &$visited,
    ): EntityInterface {
        $key = $this->buildAssociatedReplayKey($associationFactory, $entity);
        if (isset($visited[$key])) {
            return $entity;
        }
        $visited[$key] = true;

        // Keep nested callbacks/listeners aligned with the top-level persist
        // contract under outer transactions: child entities must already be
        // finalized before any afterSave logic inspects them.
        $associationFactory->finalizePersistedEntities([$entity], $associationFactory->getTable());

        $options = new ArrayObject(['associated' => true, 'atomic' => false]);
        $associationFactory->getTable()->dispatchEvent('Model.afterSave', compact('entity', 'options'));

        $entities = [$entity];
        if ($associationFactory->afterSaveCallbacks !== []) {
            $entities = $associationFactory->applyCallbacks($entities, $associationFactory->afterSaveCallbacks);
        }

        $entities = $this->replayAssociatedAfterSaveEvents($entities, $associationFactory, $visited);

        return $entities[0];
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $associationFactory Associated factory
     * @param \Cake\Datasource\EntityInterface $entity Associated entity
     *
     * @return string
     */
    private function buildAssociatedReplayKey(BaseFactory $associationFactory, EntityInterface $entity): string
    {
        $primaryKey = (array)$associationFactory->getTable()->getPrimaryKey();
        $primaryKeyValues = [];
        foreach ($primaryKey as $field) {
            $value = $entity->get($field);
            if ($value === null) {
                return spl_object_id($associationFactory) . ':object:' . spl_object_id($entity);
            }
            $primaryKeyValues[] = $value;
        }

        return spl_object_id($associationFactory) . ':pk:' . json_encode($primaryKeyValues, JSON_THROW_ON_ERROR);
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
     * Overlay the supplied $data onto the entities the factory will produce.
     *
     * Note: when an `EntityInterface` is passed, only its field values are
     * extracted via `toArray()` — the entity's identity (class, source,
     * dirty/new flags, internal `_fields`/`_original` state) is not preserved.
     * Pass an entity to `::new($entity)` or `::from($entity)` instead if you
     * need the actual entity to flow through the factory.
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
     * The state at index `$i % count($states)` is applied to the i-th entity,
     * so:
     * - if `times > count($states)` the sequence wraps around (cycles),
     * - if `times < count($states)` the trailing states are simply not used,
     * - if `times === 1` only the first state ever applies.
     *
     * Calling `sequence()` again replaces the previously stored states; it is
     * not additive.
     *
     * Each state can be:
     * - `array<string, mixed>` — patched onto the entity directly,
     * - `\Cake\Datasource\EntityInterface` — its `toArray()` is patched,
     * - `callable(\CakephpFixtureFactories\Factory\Sequence): array<string, mixed>`
     *   — invoked once per cycle with a `Sequence` context object that exposes
     *   `$s->index`, `$s->position`, `$s->total`, `$s->isFirst()`, `$s->isLast()`,
     *   plus `$s->factory` and `$s->generator` for the rare callable that
     *   needs them but doesn't have them in `use(...)` scope.
     *
     * @param \Cake\Datasource\EntityInterface|callable(\CakephpFixtureFactories\Factory\Sequence<TEntity>): array<string, mixed>|array<string, mixed> ...$states Sequence states
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
     * Cycle the values of a single column across the entities the factory
     * produces. A focused alternative to {@see self::sequence()} when you
     * only want to vary one field — the value can be any scalar, array,
     * `BackedEnum`/`UnitEnum` case, or anything Cake's marshaller accepts
     * for the target column.
     *
     * Stacking and ordering rules:
     * - Calls for **different fields** stack: each field cycles independently
     *   at its own period (`index % count(values)`), so two cycles with
     *   different cardinalities compose without interference.
     * - Calls for the **same field** replace that field's cycle (last-write-
     *   wins per field, matching normal map semantics).
     * - Plays nicely with `sequence()`: the row-level sequence runs first,
     *   then `sequenceField()` overlays its column. So if a field appears in
     *   both, the per-field overlay wins for that column.
     *
     * Example:
     * ```
     * ArticleFactory::new()
     *     ->count(6)
     *     ->sequenceField('status', 'draft', 'published') // 2-cycle
     *     ->sequenceField('priority', 1, 5, 10) // 3-cycle
     *     ->buildMany();
     * ```
     *
     * @param string $field Column to cycle.
     * @param mixed ...$values Values cycled in order. At least one is required.
     *
     * @throws \InvalidArgumentException When no values are provided.
     *
     * @return static
     */
    public function sequenceField(string $field, mixed ...$values): static
    {
        if ($values === []) {
            throw new InvalidArgumentException(
                'sequenceField() expects at least one value to cycle through.',
            );
        }

        $factory = clone $this;
        $factory->getDataCompiler()->collectFieldSequence($field, $values);

        return $factory;
    }

    /**
     * Register a callback to run on each built entity before `build*()` returns
     * and before `save*()` persists the entity.
     *
     * @param callable(TEntity, int, static): (TEntity|void) $callback Callback
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
     * @param callable(TEntity, int, static): (TEntity|void) $callback Callback
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
     * @return \CakephpFixtureFactories\Factory\DataCompiler<TEntity>
     */
    protected function getDataCompiler(): DataCompiler
    {
        return $this->dataCompiler;
    }

    /**
     * A protected class dedicated to building / collecting associations for this factory
     *
     * @return \CakephpFixtureFactories\Factory\AssociationBuilder<TEntity>
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
     * @throws \RuntimeException When called with $times > 1 on a factory that
     *   was instantiated from an existing entity (via `new($entity)` or
     *   `from($entity)`). Use `new($entity->toArray())->count($times)`
     *   instead — wrapping a single entity cannot produce N distinct ones.
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
        if ($times > 1 && $this->getDataCompiler()->isInstantiatedFromEntity()) {
            throw new RuntimeException(
                self::entityWithMultipleCountMessage(static::class, $times),
            );
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
        $factory = clone $this;
        $factory->getDataCompiler()->collectFromDefaultTemplate($fn);

        return $factory;
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
        if ($this->readBootstrapMode) {
            return clone $this;
        }

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
        /** @var \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface> $query */
        $query = self::configuredFactory()->getTable()->find();

        return $query;
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
        return self::configuredFactory()->getTable();
    }

    /**
     * Boot a factory through the normal initialize/configure path for static
     * read helpers like query() and table().
     *
     * @return static
     */
    private static function configuredFactory(): static
    {
        $factory = new static();
        $factory->readBootstrapMode = true;
        $factory->initialize();
        $factory = $factory->configure();
        $factory->readBootstrapMode = false;

        return $factory;
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Associated belongsTo factory
     *
     * @return static
     */
    public function for(BaseFactory $factory): static
    {
        if ($this->readBootstrapMode) {
            return clone $this;
        }

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
        if ($this->readBootstrapMode) {
            return clone $this;
        }

        $association = $this->resolveDirectionalAssociation($factory, false);
        if ($pivot !== []) {
            $factory = $factory->mergeAssociated(['_joinData' => $pivot]);
        }

        return $this->with($association, $factory);
    }

    /**
     * Reuse one or more already-built entities everywhere a belongsTo
     * association in this factory's build graph targets the same source table.
     *
     * Closes the silent N× duplicate-parent gap when the same parent appears
     * on multiple branches of an association tree.
     *
     * ```php
     * $author = UserFactory::new()->save();
     *
     * ArticleFactory::new()
     *     ->count(5)
     *     ->with('Comments[3]')
     *     ->recycle($author) // both Article AND each nested Comment reuse $author
     *     ->saveMany();
     * ```
     *
     * Recycles only substitute on association branches that already exist in
     * the build tree (via `with()`, `for()`, `has()`, or `initialize()`/`configure()`
     * defaults). If a recycle has no matching branch on the current factory,
     * it is silently ignored on this level but still propagates to children.
     *
     * When a factory declares two belongsTo aliases targeting the same table
     * (e.g. `Authors belongsTo Address` AND `BusinessAddress` — both targeting
     * Addresses), recycle substitutes both. Use `with('Alias', $entity)`
     * directly for per-alias control.
     *
     * @param \Cake\Datasource\EntityInterface ...$entities One or more
     *     already-built entities to reuse, keyed internally by source table.
     *
     * @throws \InvalidArgumentException If an entity has no source table set.
     *
     * @return static
     */
    public function recycle(EntityInterface ...$entities): static
    {
        if ($this->readBootstrapMode) {
            return clone $this;
        }

        $factory = clone $this;
        foreach ($entities as $entity) {
            $source = $entity->getSource();
            if ($source === '') {
                throw new InvalidArgumentException(
                    'recycle() requires entities with a known source table. '
                    . 'Build or save the entity through a factory (or call '
                    . '`$entity->setSource(\'Tablename\')`) before recycling.',
                );
            }
            if ($entity->isNew()) {
                throw new InvalidArgumentException(sprintf(
                    'recycle() requires already-persisted entities (got a `%s` with isNew()=true). '
                    . 'Save the parent via `$entity = Factory::new(...)->save()` before recycling, '
                    . 'or use `with(\'Alias\', $entity)` to attach an unsaved entity to a specific branch.',
                    $entity::class,
                ));
            }
            // Normalize the factory-internal `__ff_<hash>` suffix so the map
            // keys match association target aliases on the lookup side.
            $key = DataCompiler::normalizeTableAlias($source);
            $factory->recycledEntities[$key] = $entity;
        }

        return $factory;
    }

    /**
     * @internal Used by DataCompiler to propagate recycles down the build tree.
     *
     * @return array<string, \Cake\Datasource\EntityInterface>
     */
    public function getRecycledEntities(): array
    {
        return $this->recycledEntities;
    }

    /**
     * Whether this factory was instantiated from an explicit entity
     * (`Factory::new($entity)` or `Factory::from($entity)`).
     *
     * Used by `DataCompiler` to keep an explicit `with('Alias', $entity)`
     * from being silently overridden by `recycle()` on the parent factory.
     *
     * @internal
     */
    public function isInstantiatedFromEntity(): bool
    {
        return $this->dataCompiler->isInstantiatedFromEntity();
    }

    /**
     * Whether the user added explicit `with()` / `for()` / `has()` calls on
     * this factory after `configure()` registered its defaults.
     *
     * @internal
     */
    public function hasUserSetAssociations(): bool
    {
        return $this->dataCompiler->hasUserSetAssociations();
    }

    /**
     * Merge inherited recycles from a parent factory into a child without
     * overriding locally-set recycles. Returns the same instance if nothing
     * changes, or a clone with the merged map.
     *
     * @internal Called by DataCompiler when a child factory is about to build.
     *
     * @param array<string, \Cake\Datasource\EntityInterface> $recycles
     *
     * @return static
     */
    public function inheritRecycledEntities(array $recycles): static
    {
        if ($recycles === []) {
            return $this;
        }
        $merged = $this->recycledEntities + $recycles;
        if ($merged === $this->recycledEntities) {
            return $this;
        }
        $factory = clone $this;
        $factory->recycledEntities = $merged;

        return $factory;
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
        /** @var array<int, array{alias: string, foreignKey: string}> $matches */
        $matches = [];
        foreach ($sourceTable->associations() as $association) {
            $associationTargetsFactory = $association->getClassName() === $targetRegistryAlias
                || $association->getTarget()->getRegistryAlias() === $targetRegistryAlias
                || $association->getName() === $targetRegistryAlias;
            if (!$associationTargetsFactory) {
                continue;
            }
            $isBelongsTo = $association instanceof BelongsTo;
            if ($belongsTo !== $isBelongsTo) {
                continue;
            }
            $foreignKey = $association->getForeignKey();
            $matches[] = [
                'alias' => $association->getName(),
                'foreignKey' => is_array($foreignKey) ? implode(', ', $foreignKey) : (string)$foreignKey,
            ];
        }

        if (count($matches) === 1) {
            return $matches[0]['alias'];
        }
        if ($matches !== []) {
            throw new RuntimeException(self::ambiguousAssociationMessage(
                $belongsTo ? 'for' : 'has',
                static::class,
                $factory::class,
                $sourceTable->getRegistryAlias(),
                $targetRegistryAlias,
                $matches,
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
     * Build the paste-ready exception body for an ambiguous `for()` / `has()`
     * call. Lists every candidate alias with its foreign key and emits a
     * literal `with('Alias', TargetFactory::new())` line per candidate so the
     * fix is reachable without consulting the docs.
     *
     * @param string $direction Either `'for'` (belongsTo) or `'has'` (hasMany / hasOne / belongsToMany).
     * @param string $callerFactory FQCN of the factory the user called the directional helper on.
     * @param string $targetFactory FQCN of the factory passed in.
     * @param string $sourceAlias Source-table registry alias.
     * @param string $targetAlias Target-table registry alias.
     * @param array<int, array{alias: string, foreignKey: string}> $matches Candidate associations.
     */
    private static function ambiguousAssociationMessage(
        string $direction,
        string $callerFactory,
        string $targetFactory,
        string $sourceAlias,
        string $targetAlias,
        array $matches,
    ): string {
        $associationKind = $direction === 'for' ? 'belongsTo' : 'has* (hasOne, hasMany, belongsToMany)';
        $callerShort = self::shortName($callerFactory);
        $targetShort = self::shortName($targetFactory);

        $candidateLines = array_map(
            static fn (array $m): string => sprintf('  - %s (foreign key: %s)', $m['alias'], $m['foreignKey']),
            $matches,
        );
        $fixLines = array_map(
            static fn (array $m): string => sprintf(
                '  %s::new()->with(\'%s\', %s::new())',
                $callerShort,
                $m['alias'],
                $targetShort,
            ),
            $matches,
        );

        return sprintf(
            "%s::%s(%s::new()) cannot resolve a unique %s — `%s` declares %d associations targeting `%s`:\n%s\n\n"
            . "Use the explicit form to disambiguate:\n%s\n\n"
            . 'See: https://dereuromark.github.io/cakephp-fixture-factories/guide/troubleshooting.html#ambiguous-association',
            $callerShort,
            $direction,
            $targetShort,
            $associationKind,
            $sourceAlias,
            count($matches),
            $targetAlias,
            implode("\n", $candidateLines),
            implode("\n", $fixLines),
        );
    }

    /**
     * Strip the namespace from a class FQCN for use in user-facing snippets.
     */
    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Inputs are typed loosely as `array<EntityInterface>` because the upstream
     * sources (DataCompiler, Table::saveOrFail) only promise EntityInterface;
     * the factory's TEntity-binding is asserted here at the boundary so that
     * the callback contract — and thus userland callbacks registered via
     * afterBuild() / afterSave() — can use the concrete entity class.
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities Entities
     * @param array<int, callable(TEntity, int, static): (TEntity|void)> $callbacks Callbacks
     *
     * @return array<TEntity>
     */
    private function applyCallbacks(array $entities, array $callbacks): array
    {
        /** @var array<TEntity> $entities */
        $entities = $entities;

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
