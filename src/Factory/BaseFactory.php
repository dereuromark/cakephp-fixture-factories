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
use Cake\ORM\Association\BelongsToMany;
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
     * Per-process dedupe set for the FK-in-definition() detector. Keyed by
     * "FactoryClass::column" so the deprecation fires at most once per offending
     * column even when the factory runs thousands of times across a suite.
     *
     * @var array<string, true>
     */
    private static array $reportedFkInDefinition = [];

    /**
     * Memoised map of FK column name to declaring association alias, keyed by
     * the table's registry alias. Computed once per table per process. The
     * inner map's key type is `int|string` because PHP coerces numeric-string
     * column names to int keys; in practice all real FK column names are
     * non-numeric strings.
     *
     * @var array<string, array<int|string, string>>
     */
    private static array $fkColumnsByRegistry = [];

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
        $factoryClass = static::class;
        $factory = $factory->setDefaultData(
            function (GeneratorInterface $generator) use ($definitionFactory, $factoryClass): array {
                $data = $definitionFactory->definition($generator);
                self::detectForeignKeysInDefinition(
                    $data,
                    $definitionFactory->getTable(),
                    $factoryClass,
                    $definitionFactory->allowedForeignKeysInDefinition(),
                );

                return $data;
            },
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
        // Compensate Cake 5.4+ deferred clean()/setNew(false) under outer
        // transactions. The check used to short-circuit when the table's
        // OWN connection was not in transaction, but for associated entities
        // under a multi-connection cascade the save runs on the *parent's*
        // connection — the child's connection may legitimately be idle.
        // Skipping compensation there left the entity isNew()===true and
        // silently broke `Table::delete()` / identity-based lookups. The
        // operations below are idempotent (no harm to clean an already-clean
        // entity or setNew(false) one that already is), so run unconditionally.
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
     *   `$s->index`, `$s->position`, `$s->total`, `$s->isFirst`, `$s->isLast`,
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
     * Replay this factory's `afterSave()` callbacks on an already-persisted
     * entity, mirroring the dispatch
     * {@see self::replayAssociatedAfterSaveForEntity()} performs for entities
     * saved via the normal cascade. Returns the (possibly replaced) entity so
     * callbacks that swap it in-place are honored.
     *
     * Used by {@see \CakephpFixtureFactories\Factory\DataCompiler::mergeWithToOne()}
     * for the `foreignKey => false` belongsTo branch, where the parent is
     * persisted independently (Cake's `BelongsTo::saveAssociated()` cannot
     * handle that shape) and would otherwise miss factory-level callbacks
     * the rest of the chain runs through.
     *
     * @internal
     *
     * @param \Cake\Datasource\EntityInterface $entity The just-persisted entity.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function applyAfterSaveCallbacksToEntity(EntityInterface $entity): EntityInterface
    {
        if ($this->afterSaveCallbacks === []) {
            return $entity;
        }
        $result = $this->applyCallbacks([$entity], $this->afterSaveCallbacks);

        return $result[0] ?? $entity;
    }

    /**
     * The save options this factory would pass to `Table::saveOrFail()`
     * during its own `doPersist()` flow. Exposed so the
     * `foreignKey => false` branch in
     * {@see \CakephpFixtureFactories\Factory\DataCompiler::mergeWithToOne()}
     * can persist the parent through the same option set (checkRules,
     * atomic, the AssociationBuilder-derived `associated` list) instead of
     * defaulting and silently diverging from cascade-path behavior.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function getSaveOptionsForAssociated(): array
    {
        return $this->getSaveOptions();
    }

    /**
     * Replay `Model.afterSave` + factory afterSave callbacks for every
     * nested associated entity under `$entity`, walking THIS factory's
     * association tree. Returns the (possibly replaced) entity.
     *
     * Used by the `foreignKey => false` branch in
     * {@see \CakephpFixtureFactories\Factory\DataCompiler::mergeWithToOne()}:
     * since the parent is not attached to the root, the root's normal
     * post-save walker {@see self::replayAssociatedAfterSaveEvents()} cannot
     * reach the subtree under this parent — replay it here instead so a
     * `with('Alias', CountryFactory::new()->with('Continent', …)->afterSave(…))`
     * sees its nested callbacks regardless of the parent's persist path.
     *
     * @internal
     *
     * @param \Cake\Datasource\EntityInterface $entity The just-persisted
     *     parent whose nested associations need their afterSave replayed.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function replayAssociatedAfterSaveForTree(EntityInterface $entity): EntityInterface
    {
        $visited = [];
        $entities = $this->replayAssociatedAfterSaveEvents([$entity], $this, $visited);

        return $entities[0] ?? $entity;
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
     * Emit `E_USER_DEPRECATED` when `definition()` returns a column that is the
     * belongsTo foreign key of one of the table's declared associations.
     *
     * Foreign-key columns belong to association composition (`->with('Alias')`,
     * `->for()`, factory helpers like `withAuthor()`), never to the scalar
     * default template. A FK value set here either (a) silently overrides a
     * `->with()`-composed parent's id with garbage, or (b) creates a dangling
     * id that points at no real row when no association is attached. Both
     * outcomes mask the real source of the FK, so the column is flagged
     * regardless of whether a matching `->with()` is also present.
     *
     * The check is opt-out via `FixtureFactories.strictDefinition = false` for
     * projects migrating off the legacy pattern. The opt-out is transitional
     * and will be removed when the deprecation graduates to a hard exception
     * in the next major release.
     *
     * @param array<string, mixed> $data Data returned by definition().
     * @param \Cake\ORM\Table $table The factory's root table.
     * @param string $factoryClass FQCN of the factory, used in the message.
     * @param array<int, string> $allowed Columns the factory explicitly
     *   declares as intentional via {@see self::allowedForeignKeysInDefinition()}
     *   — not flagged. Reserved for non-managed join columns.
     */
    private static function detectForeignKeysInDefinition(array $data, Table $table, string $factoryClass, array $allowed = []): void
    {
        if (!$data) {
            return;
        }
        if (!Configure::read('FixtureFactories.strictDefinition', true)) {
            return;
        }

        $primaryKey = (array)$table->getSchema()->getPrimaryKey();
        $fkColumns = self::collectForeignKeyColumns($table);
        if (!$fkColumns) {
            return;
        }

        foreach (array_keys($data) as $column) {
            if (in_array($column, $primaryKey, true)) {
                continue;
            }
            if (!isset($fkColumns[$column])) {
                continue;
            }
            if (in_array($column, $allowed, true)) {
                continue;
            }

            $dedupeKey = $factoryClass . '::' . $column;
            if (isset(self::$reportedFkInDefinition[$dedupeKey])) {
                continue;
            }
            self::$reportedFkInDefinition[$dedupeKey] = true;

            trigger_error(sprintf(
                '%s::definition() returns "%s", which is the foreign-key column for the "%s" belongsTo association. '
                . "Move association composition out of definition() — use ->with('%s') in configure(), a forFoo() / withFoo() helper, "
                . 'or pass the association at the call site. Setting the FK column directly produces dangling ids that point at no '
                . 'real row, and silently masks the real source of the value when a parent is composed via ->with(). '
                . "Opt out transitionally with Configure::write('FixtureFactories.strictDefinition', false); "
                . 'the opt-out is removed in the next major.',
                $factoryClass,
                $column,
                $fkColumns[$column],
                $fkColumns[$column],
            ), E_USER_DEPRECATED);
        }
    }

    /**
     * Build the FK-column-to-association-alias map for the given table, memoised
     * per registry alias. Only belongsTo associations contribute, because that
     * is the side that owns the FK column locally.
     *
     * Shared with the auto-skip-composition feature in {@see DataCompiler}: both
     * the FK-in-definition() detector and the auto-skip need the same belongsTo
     * FK enumeration, so the mapping is defined once here and reused rather than
     * duplicated.
     *
     * @internal
     *
     * @param \Cake\ORM\Table $table
     *
     * @return array<int|string, string> Map of FK column name to declaring association alias.
     */
    public static function collectForeignKeyColumns(Table $table): array
    {
        // The registry alias alone is NOT a sufficient cache key: it is a
        // hash of table + connection + behaviors + events and does NOT change
        // when a belongsTo is added/removed/replaced on the table at runtime.
        // Fold a lightweight signature of the belongsTo set (alias + foreign
        // key) into the key so a runtime association change recomputes the
        // map instead of serving a stale one to the detector and the
        // build-time auto-skip. Building the signature is cheap (no schema /
        // condition introspection — that stays behind the cache).
        $belongsToSignature = [];
        foreach ($table->associations() as $association) {
            if (!$association instanceof BelongsTo) {
                continue;
            }
            $part = $association->getAlias()
                . ':' . implode(',', (array)$association->getForeignKey());
            // For a foreignKey => false custom join the protected columns are
            // recovered from `conditions`, not the (absent) foreign key — so
            // the key must also track the conditions, or replacing the same
            // alias with a different join keeps a stale map. Array conditions
            // are folded in; opaque Closure/expression conditions recover no
            // columns anyway, so they need no signature contribution.
            if ($association->getForeignKey() === false) {
                $conditions = $association->getConditions();
                if (is_array($conditions)) {
                    try {
                        $part .= ':' . md5(serialize($conditions));
                    } catch (Throwable) {
                        // Unserializable (e.g. a Closure inside the array):
                        // force a cache miss rather than risk a stale hit.
                        $part .= ':' . uniqid('', true);
                    }
                }
            }
            $belongsToSignature[] = $part;
        }
        sort($belongsToSignature);
        $cacheKey = $table->getRegistryAlias()
            . '#' . md5(implode('|', $belongsToSignature));
        if (isset(self::$fkColumnsByRegistry[$cacheKey])) {
            return self::$fkColumnsByRegistry[$cacheKey];
        }

        $columns = [];
        foreach ($table->associations() as $association) {
            if (!$association instanceof BelongsTo) {
                continue;
            }

            $declared = false;
            foreach ((array)$association->getForeignKey() as $column) {
                // A belongsTo with 'foreignKey' => false (custom-condition /
                // non-FK join) yields false here; (array)false === [false].
                // Skip any non-string / empty key so it never lands in the
                // map as a bogus 0 => alias entry.
                if (!is_string($column) || $column === '') {
                    continue;
                }
                $declared = true;
                if (!isset($columns[$column])) {
                    $columns[$column] = $association->getAlias();
                }
            }

            // No declared scalar FK (foreignKey => false): the join is done
            // through custom conditions (e.g. a uuid join). Recover the
            // source-side join column(s) from the conditions so the detector
            // still protects them. Conservative: only equality-style joins
            // that reference BOTH this table's alias and the target alias are
            // treated as join columns — pure filter conditions and opaque
            // Closure conditions are ignored (no false positives).
            if (!$declared) {
                foreach (self::joinColumnsFromConditions($association) as $column) {
                    if (!isset($columns[$column])) {
                        $columns[$column] = $association->getAlias();
                    }
                }
            }
        }

        return self::$fkColumnsByRegistry[$cacheKey] = $columns;
    }

    /**
     * Best-effort extraction of the source-table join column(s) from a
     * belongsTo association whose `foreignKey` is `false` and whose join is
     * expressed via custom `conditions` (the classic uuid-join pattern:
     * `['Source.foo_uuid = Target.uuid']` or `['Source.foo_uuid' => 'Target.uuid']`).
     *
     * Only equality references that mention BOTH the source alias and the
     * target alias are accepted, so a plain filter (`'Source.status' => 1`,
     * `'Source.deleted = 0'`) is never mistaken for a join column. Closure
     * conditions are opaque and yield nothing.
     *
     * @param \Cake\ORM\Association\BelongsTo<\Cake\ORM\Table> $association
     *
     * @return array<int, string> Source-table column names participating in the join.
     */
    private static function joinColumnsFromConditions(BelongsTo $association): array
    {
        $conditions = $association->getConditions();
        if (!is_array($conditions)) {
            return [];
        }

        $sourceAlias = $association->getSource()->getAlias();
        $targetAlias = $association->getAlias();
        $src = preg_quote($sourceAlias, '/');
        $tgt = preg_quote($targetAlias, '/');

        $cols = [];
        foreach ($conditions as $key => $value) {
            if (is_string($key)) {
                // 'Source.col' => 'Target.col'  (join)
                // 'Source.col' => <scalar>      (filter — ignored)
                if (
                    preg_match('/^' . $src . '\.(\w+)$/', $key, $m)
                    && is_string($value)
                    && preg_match('/^' . $tgt . '\.\w+$/', $value)
                ) {
                    $cols[] = $m[1];
                }

                continue;
            }
            // Integer key: a string expression such as
            // 'Source.col = Target.col' (operator-agnostic). Require both
            // aliases present so filters never qualify.
            if (
                is_string($value)
                && preg_match('/\b' . $tgt . '\.\w+/', $value)
                && preg_match_all('/\b' . $src . '\.(\w+)/', $value, $m)
            ) {
                foreach ($m[1] as $col) {
                    $cols[] = $col;
                }
            }
        }

        return $cols;
    }

    /**
     * Reset the FK-in-definition() detector's process-wide caches. Intended for
     * the test suite of this plugin (and downstream test-helper code), so that
     * each test starts with an empty dedupe set and a freshly-resolved table
     * FK map.
     */
    public static function resetForeignKeyInDefinitionDetector(): void
    {
        self::$reportedFkInDefinition = [];
        self::$fkColumnsByRegistry = [];
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
            // Honor the bracket-count syntax for leaf-factory calls too.
            // The non-factory path goes through getAssociatedFactory() which
            // applies the bracket count via getTimeBetweenBrackets(); this
            // branch used to skip it, so `with('Alias[3]', SomeFactory::new())`
            // silently dropped the [3] and built 1 row instead of 3.
            $times = $factory->getAssociationBuilder()->getTimeBetweenBrackets($associationName);
            if ($times !== null) {
                $associatedFactory = $associatedFactory->count($times);
            }
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
     * Compose every belongsTo parent the root table *requires* — i.e. each
     * belongsTo whose single scalar foreign-key column is `NOT NULL` in the
     * schema — recursively down the chain, so a row built by this factory
     * satisfies its NOT NULL FK constraints out of the box.
     *
     * This is the ergonomic counterpart to the FK-in-`definition()` detector
     * (`FixtureFactories.strictDefinition`, see
     * {@link https://dereuromark.github.io/cakephp-fixture-factories/guide/foreign-keys-in-definition Foreign keys in definition()}).
     * `strictDefinition` pushes FK population *out* of `definition()`; without
     * a counterpart, every factory for a table with NOT NULL belongsTo FKs then
     * needs hand-written `->with('Alias')` boilerplate just to persist.
     * `withRequiredParents()` is that counterpart: one call composes the whole
     * required chain.
     *
     * What counts as "required" — and what does NOT:
     *
     * - A belongsTo whose foreign key is a *single scalar column* that is
     *   `NOT NULL` in the table schema is auto-resolved.
     * - A belongsTo with a *composite* foreign key, or one declared
     *   `'foreignKey' => false` (custom-condition / non-FK join), is **never**
     *   auto-resolved. These are exactly the brittle edge cases that PR #85 hit
     *   — guessing how to build them is unsafe. Opt them in explicitly by
     *   overriding {@see self::requiredParentAssociations()}.
     * - A nullable FK is not composed: the row persists fine without it, and
     *   silently fabricating an optional parent hides intent.
     *
     * Side-effect free: like every other `with*()` / `for*()` chain method,
     * this only *composes* parent factories. Nothing is written to the
     * database until the root factory is persisted (`->save()` /
     * `->persist()` / `->saveMany()`), so the parents are created in the same
     * unit as the root entity — atomic, no orphan rows on a later override or
     * a failed root save, and `->build()` stays purely in-memory.
     *
     * Composes cleanly with the rest of the composition layer:
     *
     * - An alias already composed via `->with('Alias', SomeFactory::new())`
     *   or a `configure()` default is kept (the caller's parent is not
     *   replaced) but is itself recursively enriched, so the parent's *own*
     *   required grandchildren are satisfied too. A parent composed from a
     *   concrete entity (`->with('Alias', $entity)`) is left fully untouched —
     *   the caller specified that row exactly.
     * - An alias listed in `$except` is skipped (pin its FK literally at the
     *   call site for a column-scope assertion).
     * - Behaves correctly alongside `autoSkipComposeOnExplicitForeignKey`: an
     *   alias whose FK the caller already pinned (with a non-null value) is
     *   not double-composed.
     *
     * Sharing a parent (dedup): `withRequiredParents()` deliberately does NOT
     * persist anything itself, so it cannot reuse a pre-existing row. When you
     * want a counted batch — or several factories — to share one parent, build
     * it yourself and hand it to {@see self::recycle()} (the established
     * pattern), which composes cleanly with this method:
     *
     * ```php
     * $country = CountryFactory::new()->save();
     * AuthorFactory::new()->count(50)
     *     ->withRequiredParents()
     *     ->recycle($country) // every row's chain reuses this one Country
     *     ->saveMany();
     * ```
     *
     * This is the *pragmatic default* for "I just need a persistable row".
     * When a test asserts on a specific parent, still attach it explicitly
     * with `->with('Alias', $entity)` so the assertion's intent is visible.
     *
     * Note: a root table with an unsatisfiable required (NOT NULL) belongsTo
     * cycle raises a `FixtureFactoryException` — see the cycle handling below.
     *
     * Depth: by default the whole NOT NULL chain is recursed. Pass `$maxDepth`
     * to compose only the first N levels below the root (`1` = direct parents
     * only). A cap below the real required depth yields an un-persistable row
     * — the caller's responsibility, exactly like `$except`. This includes the
     * cycle fast-fail: a required-parent cycle *beyond* the cap is not reached,
     * so it surfaces as a save-time NOT NULL error rather than the
     * `FixtureFactoryException`. A cycle *within* the cap still throws. Opt into
     * `$strict` to turn that silent shortfall into an actionable exception at
     * call time (it also restores an actionable message for a capped cycle).
     *
     * @param array<int, string> $except Association aliases to skip.
     * @param int|null $maxDepth Cap how many *levels* of required parents are
     *   composed below the root. `null` (default) recurses the whole NOT NULL
     *   chain. `1` composes only the root's direct required parents, `2` those
     *   plus their parents, and so on. A cap below the real required depth can
     *   produce an un-persistable row (a deeper NOT NULL FK left unsatisfied) —
     *   by design, the caller's responsibility, exactly like `$except`.
     * @param bool $strict When `true` and `$maxDepth` truncates the chain so a
     *   composed boundary parent still has its own unsatisfied required
     *   belongsTo, fail loudly at call time with an actionable
     *   `FixtureFactoryException` instead of silently producing an
     *   un-persistable row. Default `false` keeps the silent contract. A no-op
     *   without `$maxDepth` (a full chain is never truncated).
     *
     * @throws \InvalidArgumentException When `$maxDepth` is provided but not at
     *   least 1 — "compose zero required parents" is just not calling this.
     *   `$strict` is set and `$maxDepth` leaves a required parent unsatisfied.
     *
     * @return static
     */
    public function withRequiredParents(
        array $except = [],
        ?int $maxDepth = null,
        bool $strict = false,
    ): static {
        if ($maxDepth !== null && $maxDepth < 1) {
            throw new InvalidArgumentException(sprintf(
                'withRequiredParents() $maxDepth must be a positive integer or null, got %d. '
                . 'To compose no required parents, simply do not call withRequiredParents().',
                $maxDepth,
            ));
        }

        return $this->doWithRequiredParents($except, [], $maxDepth, 0, $strict);
    }

    /**
     * @param array<int, string> $except Root-scoped association aliases to skip.
     * @param array<int, string> $visitedTables Target table registry names
     *   already on the current recursion chain — used to detect an
     *   unsatisfiable required-parent cycle (self-referential or
     *   `A -> B -> A` NOT NULL graphs) and fail fast with an actionable
     *   exception instead of recursing until the stack overflows.
     * @param int|null $maxDepth Remaining recursion budget (see
     *   {@see self::withRequiredParents()}); `null` is unbounded.
     * @param int $depth Current level below the root (0 = root).
     * @param bool $strict Throw when `$maxDepth` truncates the chain leaving a
     *   composed boundary parent with its own unsatisfied required belongsTo.
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException When a
     *   required (NOT NULL) belongsTo cycle is detected, or when `$strict` and
     *   the depth cap leaves a required parent unsatisfied.
     *
     * @return static
     */
    private function doWithRequiredParents(
        array $except,
        array $visitedTables,
        ?int $maxDepth,
        int $depth,
        bool $strict,
    ): static {
        if ($this->readBootstrapMode) {
            return clone $this;
        }

        $factory = clone $this;
        // Identify tables by their physical DB table name. A factory's own
        // registry alias carries an internal `__ff_<hash>` suffix and a
        // belongsTo target's registry alias is the *association* alias, so
        // only getTable() is a stable cross-level identity for cycle checks.
        $ownTable = $factory->getTable()->getTable();
        $visitedTables[] = $ownTable;

        $aliases = $factory->resolveRequiredParentAliases($except);
        if ($aliases === []) {
            return $factory;
        }

        foreach ($aliases as $alias) {
            $association = $factory->getAssociationBuilder()->getAssociation($alias);
            $targetTable = $association->getTarget()->getTable();

            // If the caller already composed this alias with a *factory*
            // (explicitly or via configure() defaults), enrich THAT factory
            // rather than skipping it — otherwise its own required parents
            // (the grandchildren) stay unsatisfied and the save still fails.
            // A parent instantiated from a concrete entity is left untouched:
            // the caller fully specified that row (and, importantly, it
            // terminates an otherwise self-referential / cyclic chain).
            $composed = $factory->getAssociationBuilder()->getAssociations();
            $existing = $composed[$alias] ?? null;
            if ($existing instanceof BaseFactory) {
                if ($existing->isInstantiatedFromEntity()) {
                    continue;
                }
                $parentFactory = $existing;
                // The caller composed this branch themselves (explicit
                // ->with()/->for(), or a configure() default). Leave its
                // classification untouched so recycle() keeps its documented
                // "explicit per-branch intent wins" semantics.
                $autoResolved = false;
            } else {
                $parentFactory = $factory->getAssociationBuilder()->getAssociatedFactory($alias);
                // We synthesized this parent solely to satisfy a NOT NULL FK:
                // auto-scaffolding, not per-branch user intent.
                $autoResolved = true;
            }

            // Only now — for an alias we would actually recurse into — reject
            // an unsatisfiable required (NOT NULL) belongsTo cycle
            // (self-referential, or A -> B -> A). The check runs *after* the
            // explicit-parent handling above so a caller who already broke the
            // cycle with `->with('Alias', $entity)` is honored, not rejected.
            // A real cycle cannot be auto-resolved: no row in it can be
            // inserted without a parent row that itself needs one, so fail
            // loudly here instead of with a confusing NOT NULL at save time.
            if (in_array($targetTable, $visitedTables, true)) {
                throw new FixtureFactoryException(sprintf(
                    'withRequiredParents() found a required (NOT NULL) belongsTo cycle through the "%s" '
                    . 'association (table "%s" is already on the chain: %s). Such a cycle cannot be '
                    . 'auto-resolved. Break it manually: compose a terminating parent explicitly with '
                    . '->with(\'%s\', $entity), pin the FK and pass the alias in $except '
                    . '(e.g. ->withRequiredParents([\'%s\'])), or exclude it via the '
                    . 'excludedRequiredParentAssociations() override hook.',
                    $alias,
                    $targetTable,
                    implode(' -> ', $visitedTables),
                    $alias,
                    $alias,
                ));
            }

            // Recurse so the whole NOT NULL chain is satisfied, not just the
            // first level. `$except` is intentionally root-scoped: a deeper
            // level's required parents are always needed for that row to
            // persist regardless of what the caller pinned.
            //
            // `maxDepth` caps how deep that recursion goes: the parent itself
            // (at $depth + 1) is still composed below, but it is only enriched
            // with ITS own required parents while that next level stays within
            // the cap. Reaching the cap with a deeper NOT NULL FK unsatisfied
            // is the caller's responsibility — same contract as `$except`.
            if ($maxDepth === null || $depth + 1 < $maxDepth) {
                $parentFactory = $parentFactory->doWithRequiredParents(
                    [],
                    $visitedTables,
                    $maxDepth,
                    $depth + 1,
                    $strict,
                );
            } elseif ($strict) {
                // The cap stops us recursing into $parentFactory. If that
                // boundary parent still has a required belongsTo that is NOT
                // already composed on it (configure()/with()/for()) and NOT
                // pinned/excepted, the composed row cannot persist. Note a
                // later recycle() cannot rescue it: recycle only substitutes
                // *composed* branches, and the cap stopped that branch from
                // being composed. Opt-in strictness turns that silent
                // shortfall — and a capped cycle — into an actionable failure
                // at call time.
                $composedOnParent = $parentFactory->getAssociationBuilder()->getAssociations();
                foreach ($parentFactory->resolveRequiredParentAliases([]) as $unmetAlias) {
                    if (isset($composedOnParent[$unmetAlias])) {
                        continue;
                    }

                    throw new FixtureFactoryException(sprintf(
                        'withRequiredParents(maxDepth: %d, strict: true): the depth cap leaves the '
                        . 'required belongsTo "%s" on table "%s" (reached via "%s") unsatisfied, so '
                        . 'the composed row cannot persist. Raise maxDepth, pin that FK, '
                        . 'add "%s" to $except, or drop strict to accept the silent contract. '
                        . '(recycle() cannot help here: the cap stopped this branch from being '
                        . 'composed, and recycle only substitutes composed branches.)',
                        $maxDepth,
                        $unmetAlias,
                        $parentFactory->getTable()->getTable(),
                        $alias,
                        $alias,
                    ));
                }
            }

            // Compose the parent *factory* only — no persistence here. The
            // parent is built and saved as part of the root factory's own
            // persist unit, so this stays side-effect free and atomic, and a
            // caller's recycle() still dedups the chain across a batch.
            $factory = $factory->with($alias, $parentFactory);

            // An auto-resolved parent is scaffolding, not per-branch user
            // intent. `with()` lands it in the explicit-association bucket
            // (after configure() was already snapshotted), which would flip
            // hasUserSetAssociations() and make recycle() refuse to substitute
            // this node — silently rebuilding a mid-chain parent the caller
            // recycle()d. Demote it to a configure()-style default so recycle
            // substitution works at every depth, while a caller-composed
            // branch keeps its intent.
            if ($autoResolved) {
                $factory->getDataCompiler()->demoteAssociationToDefault($alias);
            }
        }

        return $factory;
    }

    /**
     * Resolve which belongsTo aliases of the root table are *required* parents
     * for {@see self::withRequiredParents()}.
     *
     * By default this is every belongsTo whose foreign key is a single scalar
     * column that is `NOT NULL` in the schema, minus:
     *
     * - aliases listed in `$except`,
     * - aliases whose FK the caller pinned (non-null) at the call site,
     * - aliases added explicitly by the {@see self::requiredParentAssociations()}
     *   hook.
     *
     * An alias already composed via `->with()` / `->for()` / `configure()` is
     * still returned: the caller's factory is recursively enriched (or, if it
     * was instantiated from a concrete entity, left untouched) by
     * {@see self::doWithRequiredParents()}.
     *
     * Composite-key and `foreignKey => false` associations are never included
     * automatically — only the override hook can opt them in.
     *
     * @param array<int, string> $except Association aliases to skip.
     *
     * @return array<int, string> Ordered list of belongsTo aliases to compose.
     */
    private function resolveRequiredParentAliases(array $except): array
    {
        $table = $this->getTable();
        $schema = $table->getSchema();

        $additional = $this->requiredParentAssociations();
        // Caller-pinned FK columns (Factory::new(['fk' => x]), ->state(),
        // ->setField(), ->patchData(), sequence*()), resolvable at chain time.
        // A *non-null* pinned FK means the parent is already satisfied, so the
        // alias is skipped — but ONLY while autoSkipComposeOnExplicitForeignKey
        // is on (its default). With the opt-out flag off the library's legacy
        // contract is "composed parent overrides the explicit FK", so to stay
        // consistent withRequiredParents() must still compose the parent then.
        // A null pin never satisfies a NOT NULL FK (matches the auto-skip
        // feature's non-null semantics).
        $honorPinnedFks = (bool)Configure::read(
            'FixtureFactories.autoSkipComposeOnExplicitForeignKey',
            true,
        );
        $pinnedFields = $honorPinnedFks ? $this->getDataCompiler()->getPinnedFields() : [];
        // Probe hidden FK columns on a Factory::new($entity) payload via
        // has()/get() (toArray() drops hidden properties) — matches the
        // build-time auto-skip path.
        $instantiationEntity = $honorPinnedFks
            ? $this->getDataCompiler()->getInstantiationEntity()
            : null;

        $aliases = [];
        foreach ($table->associations() as $association) {
            if (!$association instanceof BelongsTo) {
                continue;
            }

            $alias = $association->getAlias();
            if (in_array($alias, $except, true)) {
                continue;
            }
            // Note: an alias already composed via ->with()/configure() is NOT
            // skipped here — doWithRequiredParents() recurses into an attached
            // *factory* so its own required grandchildren get satisfied too
            // (an entity-instantiated parent is left untouched there).
            // The caller pinned this association's FK literally at the call
            // site: the parent is already satisfied. Composing it would
            // override the pinned value (an explicit ->with() always wins over
            // autoSkipComposeOnExplicitForeignKey), so skip the alias entirely.
            $foreignKeysForAlias = array_values(array_filter(
                (array)$association->getForeignKey(),
                static fn ($fk): bool => is_string($fk) && $fk !== '',
            ));
            if ($foreignKeysForAlias !== []) {
                $allPinnedNonNull = true;
                foreach ($foreignKeysForAlias as $fkColumn) {
                    $pinned =
                        (array_key_exists($fkColumn, $pinnedFields) && $pinnedFields[$fkColumn] !== null)
                        || (
                            $instantiationEntity !== null
                            && $instantiationEntity->has($fkColumn)
                            && $instantiationEntity->get($fkColumn) !== null
                        );
                    if (!$pinned) {
                        $allPinnedNonNull = false;

                        break;
                    }
                }
                if ($allPinnedNonNull) {
                    continue;
                }
            }

            $foreignKeys = (array)$association->getForeignKey();
            // Composite key, or `foreignKey => false` (custom-condition join):
            // never auto-resolved — see PR #85. Opt in via the override hook.
            if (count($foreignKeys) !== 1) {
                continue;
            }
            $column = $foreignKeys[0];
            if (!is_string($column) || $column === '') {
                continue;
            }
            if (!$schema->hasColumn($column)) {
                continue;
            }
            // A shared-primary-key 1:1 (child.id is both PK and the belongsTo
            // FK) is intentionally NOT excluded: it is a legitimate single
            // scalar NOT NULL belongsTo and the parent must be composed for
            // the child to persist.
            if ($schema->isNullable($column)) {
                continue;
            }

            $aliases[] = $alias;
        }

        foreach ($additional as $alias) {
            if (in_array($alias, $except, true)) {
                continue;
            }
            if (!in_array($alias, $aliases, true)) {
                $aliases[] = $alias;
            }
        }

        // Factory-class-level exclude hook — the symmetric counterpart to the
        // additive opt-in and to per-call $except. Exclude wins over both.
        $excluded = $this->excludedRequiredParentAssociations();
        if ($excluded !== []) {
            $aliases = array_values(array_diff($aliases, $excluded));
        }

        return $aliases;
    }

    /**
     * Add extra belongsTo aliases to the automatic required-parent set used by
     * {@see self::withRequiredParents()}.
     *
     * Automatic detection always includes the root table's belongsTo
     * associations whose foreign key is a single scalar NOT NULL column in the
     * schema. Return additional aliases here to opt in associations
     * auto-detection refuses on its own — typically a *nullable* single-scalar
     * FK the factory wants composed regardless. Returned aliases are unioned
     * onto the auto-detected set, then any in
     * {@see self::excludedRequiredParentAssociations()} or the per-call
     * `$except` argument are subtracted.
     *
     * `foreignKey => false` custom-condition joins are supported: the parent
     * is built and saved independently of the cascade (Cake's
     * `BelongsTo::saveAssociated` cannot handle them — the relation is queried
     * by custom conditions at read time, with no FK column to populate). The
     * parent row persists and is reachable via `find()->contain()`, but is
     * NOT attached to the in-memory root entity post-save (attaching would
     * re-fire the broken cascade). Composite-key belongsTo are also supported
     * here: the parent is composed like any other to-one association and Cake
     * populates every FK component from the target binding keys at save time.
     * See {@see self::excludedRequiredParentAssociations()} for the symmetric
     * factory-class-level *exclude* hook.
     *
     * @return array<int, string> Additional aliases to compose.
     */
    protected function requiredParentAssociations(): array
    {
        return [];
    }

    /**
     * Permanently drop belongsTo aliases from the automatic required-parent
     * set used by {@see self::withRequiredParents()} — the factory-class-level
     * counterpart to the per-call `$except`. Use for FKs satisfied another
     * way (a DB default, a trigger, a custom join the caller always supplies)
     * so call sites do not have to repeat `->withRequiredParents(['Alias'])`.
     *
     * Composition with the rest of the resolver:
     * - Wins over {@see self::requiredParentAssociations()} additive opt-ins.
     * - Composes with the per-call `$except` argument (both subtract).
     * - Scoped to *this* factory class — parent factories higher up the chain
     *   each apply their own exclude list via their own resolver invocation.
     *
     * @return array<int, string> Aliases to exclude.
     */
    protected function excludedRequiredParentAssociations(): array
    {
        return [];
    }

    /**
     * Columns that `definition()` intentionally returns and the
     * `strictDefinition` detector must NOT flag.
     *
     * Reserved for **non-managed** join columns: a `foreignKey => false`
     * custom-condition belongsTo (e.g. a uuid-condition join) whose column
     * CakePHP never manages. strictDefinition exists to stop a dangling
     * *managed* FK id from masking a real composed parent; a non-managed
     * condition-join column has no managed pointer to dangle, so a generated
     * value there is not that anti-pattern. The detector still flags every
     * genuinely managed FK — listing a managed FK column here defeats the
     * check, so don't.
     *
     * @return array<int, string> Column names exempt from the detector.
     */
    protected function allowedForeignKeysInDefinition(): array
    {
        return [];
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
     * Attach a belongsTo associated factory.
     *
     * Pass `$alias` to disambiguate when this factory's table has multiple
     * belongsTo associations pointing at the same target — e.g.
     * `Authors belongsTo Address AND BusinessAddress`, both targeting `Addresses`:
     *
     * ```php
     * AuthorFactory::new()
     *     ->for(AddressFactory::new(['street' => 'Home']), 'Address')
     *     ->for(AddressFactory::new(['street' => 'Office']), 'BusinessAddress')
     *     ->save();
     * ```
     *
     * When `$alias` is null, the alias is auto-resolved by the associated
     * factory's target table — equivalent to the previous single-arg form.
     *
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Associated belongsTo factory.
     * @param string|null $alias Explicit association alias on the source table; auto-resolved when null.
     *
     * @return static
     */
    public function for(BaseFactory $factory, ?string $alias = null): static
    {
        if ($this->readBootstrapMode) {
            return clone $this;
        }

        if ($alias === null) {
            $alias = $this->resolveDirectionalAssociation($factory, true);
        } else {
            $this->guardAliasDirection($alias, true, $factory);
        }

        return $this->with($alias, $factory);
    }

    /**
     * Attach a hasMany / hasOne / belongsToMany associated factory.
     *
     * Pass `$alias` to disambiguate when this factory's table has multiple
     * has* associations targeting the same model — e.g.
     * `Countries hasMany Cities AND VirtualCities`, both targeting `Cities`:
     *
     * ```php
     * CountryFactory::new()
     *     ->has(CityFactory::new()->count(3), 'Cities')
     *     ->has(CityFactory::new()->count(2), 'VirtualCities')
     *     ->save();
     * ```
     *
     * When `$alias` is null, the alias is auto-resolved by the associated
     * factory's target table — equivalent to the previous single-arg form.
     *
     * **Pivot precedence (belongsToMany only):** `$pivot` is applied as
     * `state(['_joinData' => $pivot])` on the passed factory just before it
     * is registered. Because `state()` merges over earlier state, a
     * `_joinData` value already set on the passed factory wins on a
     * per-key basis where it overlaps; non-overlapping keys are kept from
     * `$pivot`. For a totally pivot-only build, prefer the explicit
     * `$pivot` argument and don't pre-load `_joinData` on the child.
     *
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Associated factory.
     * @param string|null $alias Explicit association alias on the source table; auto-resolved when null.
     * @param array<string, mixed> $pivot Pivot data for belongsToMany joins.
     *
     * @throws \RuntimeException
     *
     * @return static
     */
    public function has(BaseFactory $factory, ?string $alias = null, array $pivot = []): static
    {
        if ($this->readBootstrapMode) {
            return clone $this;
        }

        if ($alias === null) {
            $alias = $this->resolveDirectionalAssociation($factory, false);
        } else {
            $this->guardAliasDirection($alias, false, $factory);
        }
        if ($pivot !== []) {
            // Pivot data only has a place to land on belongsToMany joins
            // (Cake writes it into `_joinData` on the junction row). For
            // hasOne / hasMany the marshaller has no junction to populate,
            // so the data was silently dropped on save. Reject loudly.
            $association = $this->getTable()->getAssociation($alias);
            if (!$association instanceof BelongsToMany) {
                throw new RuntimeException(sprintf(
                    "%s::has() pivot data is only meaningful for belongsToMany; alias '%s' "
                    . 'is a `%s` association on `%s`. Drop the pivot argument, '
                    . 'or use the alias that points to a belongsToMany junction.',
                    self::shortName(static::class),
                    $alias,
                    strtolower(self::shortName($association::class)),
                    $this->getTable()->getRegistryAlias(),
                ));
            }
            // Patch `_joinData` into every built child so Cake's belongsToMany
            // marshaller writes the pivot columns onto the join row. The
            // previous `mergeAssociated(['_joinData' => $pivot])` only set
            // the marshaller `associated` config — it never populated the
            // entity's data, so pivot values silently dropped on save.
            $factory = $factory->state(['_joinData' => $pivot]);
        }

        return $this->with($alias, $factory);
    }

    /**
     * Validate an explicit `$alias`:
     *
     * - Unknown alias → rethrow with a paste-ready list of valid aliases on
     *   this factory's source table, instead of letting Cake's generic
     *   `"<assoc> is not defined"` bubble up.
     * - Cardinality mismatch → reject `for('hasMany-alias')` or
     *   `has('belongsTo-alias')` so a typo doesn't silently misbuild the graph.
     *
     * @param string $alias Association alias on this factory's source table.
     * @param bool $belongsTo `true` when called from `for()`, `false` from `has()`.
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>|null $factory
     *     Associated factory whose target table should match the alias's
     *     target. When `null`, only the direction guard runs.
     *
     * @throws \RuntimeException When the alias is unknown or resolves to the wrong cardinality.
     */
    private function guardAliasDirection(string $alias, bool $belongsTo, ?BaseFactory $factory = null): void
    {
        $caller = $belongsTo ? 'for' : 'has';
        $callerShort = self::shortName(static::class);

        try {
            $association = $this->getTable()->getAssociation($alias);
        } catch (InvalidArgumentException $e) {
            $available = [];
            foreach ($this->getTable()->associations() as $declared) {
                $available[] = $declared->getName();
            }
            sort($available);

            throw new RuntimeException(sprintf(
                "%s::%s() got unknown alias '%s' on `%s`. Available aliases: %s. "
                . '(Typo? Missing belongsTo / hasMany declaration on the table class?)',
                $callerShort,
                $caller,
                $alias,
                $this->getTable()->getRegistryAlias(),
                $available === [] ? '(none declared)' : '`' . implode('`, `', $available) . '`',
            ), 0, $e);
        }

        $isBelongsTo = $association instanceof BelongsTo;
        if ($belongsTo !== $isBelongsTo) {
            $expected = $belongsTo ? 'belongsTo' : 'has* (hasOne, hasMany, belongsToMany)';
            $actual = $isBelongsTo ? 'belongsTo' : 'has*';

            throw new RuntimeException(sprintf(
                "%s::%s() with alias '%s' refers to a %s association — expected %s. "
                . "Use the matching directional helper, or `with('%s', ...)` if you "
                . 'want the lower-level form.',
                $callerShort,
                $caller,
                $alias,
                $actual,
                $expected,
                $alias,
            ));
        }

        // Target-match guard: a `Foo::for(BarFactory, 'Address')` whose Bar
        // factory does NOT target the Address alias's table is a mis-wire
        // that the auto-resolve path would have caught — match it here too,
        // mirroring the disjuncts in resolveDirectionalAssociation().
        if ($factory !== null) {
            $factoryRoot = $factory->getRootTableRegistryName();
            $targets = $association->getClassName() === $factoryRoot
                || $association->getTarget()->getRegistryAlias() === $factoryRoot
                || $association->getName() === $factoryRoot;
            if (!$targets) {
                throw new RuntimeException(sprintf(
                    "%s::%s() alias '%s' targets `%s`, but `%s` builds `%s`. "
                    . 'Pass a factory whose root table matches the alias target, '
                    . 'or drop the alias argument to let auto-resolution catch it.',
                    $callerShort,
                    $caller,
                    $alias,
                    $association->getTarget()->getRegistryAlias(),
                    $factory::class,
                    $factoryRoot,
                ));
            }
        }
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
     * Only **belongsTo** branches are substituted. A composed `hasOne` /
     * `hasMany` / `belongsToMany` child is NOT replaced with a recycled
     * entity — its identity is the *factory's* build (the recycle map only
     * propagates *into* it so its own belongsTo branches can substitute).
     * If you need a specific child entity, attach it explicitly with
     * `with('Alias', $entity)` instead.
     *
     * Within a single call, two entities for the same source table are
     * rejected (`InvalidArgumentException`) — that shape is almost always a
     * typo for "I want one of these but I'm not sure which". Across multiple
     * `recycle()` calls, last wins (a chained `->recycle($a)->recycle($b)` on
     * the same source is treated as an intentional update).
     *
     * @param \Cake\Datasource\EntityInterface ...$entities One or more
     *     already-built entities to reuse, keyed internally by source table.
     *
     * @throws \InvalidArgumentException If an entity has no source table set,
     *     is unsaved, or collides with another entity for the same source
     *     table in the same call.
     *
     * @return static
     */
    public function recycle(EntityInterface ...$entities): static
    {
        if ($this->readBootstrapMode) {
            return clone $this;
        }

        $factory = clone $this;
        $seenInCall = [];
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

            // Within a SINGLE call, reject duplicate-source entities — the
            // result would silently keep only the last one, almost always a
            // typo for "I want one of these but I'm not sure which".
            // Across SEPARATE recycle() calls last-wins is still allowed
            // (intentional update of the recycle map for that table).
            if (isset($seenInCall[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'recycle() received two entities for the same source table `%s` in one call — '
                    . 'the second would silently drop the first. Pick one, or chain a second '
                    . '`->recycle($other)` call if you intentionally want to overwrite the map.',
                    $key,
                ));
            }
            $seenInCall[$key] = true;

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
     * Note: this flag only tracks user-set *associations* (the alias-keyed
     * association list). It does NOT track `state(['_joinData' => ...])` —
     * pivot data is keyed inside the entity's own data, not in the
     * association list — so a factory carrying only pivot state still
     * reports `false` here and remains a candidate for `recycle()`.
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
