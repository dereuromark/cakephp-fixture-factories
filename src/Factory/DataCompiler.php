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
use Cake\Database\Driver\Postgres;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Association;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\HasOne;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\TestSuite\FactoryTableTracker;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use Closure;
use InvalidArgumentException;

/**
 * Class DataCompiler
 *
 * The TEntity template tracks the *parent* factory's entity type. Associated
 * child factories collected in `$dataFromAssociations` and friends carry
 * their own (different) entity types and stay typed as
 * `BaseFactory<EntityInterface>`.
 *
 * @internal
 *
 * @template TEntity of \Cake\Datasource\EntityInterface
 */
class DataCompiler
{
    /**
     * @var string
     */
    public const MODIFIED_UNIQUE_PROPERTIES = '___data_compiler__modified_unique_properties';

    /**
     * @var string
     */
    public const IS_ASSOCIATED = '___data_compiler__is_associated';

    /**
     * @var \Closure|array<string, mixed>
     */
    private array|Closure $dataFromDefaultTemplate = [];

    /**
     * @var \Cake\Datasource\EntityInterface|callable|array<mixed>|string
     */
    private $dataFromInstantiation = [];

    /**
     * @var array<string, mixed>
     */
    private array $dataFromPatch = [];

    /**
     * @var array<int, \Cake\Datasource\EntityInterface|callable|array<string, mixed>>
     */
    private array $sequenceData = [];

    /**
     * Per-field cycle map populated by `BaseFactory::sequenceField()`. Each
     * entry is a list of values cycled at its own period during compilation,
     * applied AFTER `sequence()` so a per-field overlay wins for that column.
     *
     * @var array<string, array<int, mixed>>
     */
    private array $fieldSequences = [];

    private int $sequenceIndex = 0;

    /**
     * @var array<string, array<int, \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>>>
     */
    private array $dataFromAssociations = [];

    /**
     * @var array<string, array<int, \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>>>
     */
    private array $dataFromDefaultAssociations = [];

    /**
     * @var array<string, int|string>|null
     */
    private ?array $primaryKeyOffset = [];

    /**
     * @var array<string>
     */
    private array $enforcedFields = [];

    /**
     * @var array<string>
     */
    private array $skippedSetters = [];

    /**
     * Depth counter so nested persist calls (e.g. one factory persisting
     * another from inside an `afterBuild` callback) don't end persist mode for
     * the outer flow when the inner one returns.
     */
    private static int $persistDepth = 0;

    /**
     * Dedupe warnings about `configure()` defaults being auto-skipped when
     * caller-supplied FK state pins the parent. Keyed by
     * `factory-class::association::fk1,fk2`.
     *
     * @var array<string, bool>
     */
    private static array $reportedAutoSkippedConfigureAssociations = [];

    /**
     * @var \CakephpFixtureFactories\Factory\BaseFactory<TEntity>
     */
    private BaseFactory $factory;

    /**
     * @var bool
     */
    private bool $setPrimaryKey = true;

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<TEntity> $factory Master factory
     */
    public function __construct(BaseFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<TEntity> $factory Master factory
     *
     * @return void
     */
    public function setFactory(BaseFactory $factory): void
    {
        $this->factory = $factory;
    }

    /**
     * Data passed in the instantiation by array
     *
     * @param \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>|string $data Injected data.
     *
     * @return void
     */
    public function collectFromInstantiation(EntityInterface|array|string $data): void
    {
        $this->dataFromInstantiation = $data;
    }

    /**
     * Whether the factory was instantiated from a single existing entity
     * (via `BaseFactory::new($entity)` or `BaseFactory::from($entity)`).
     *
     * Used by the count guards: combining an entity payload with `times > 1`
     * would return N references to the same mutated entity, which is never
     * what callers want.
     */
    public function isInstantiatedFromEntity(): bool
    {
        return $this->dataFromInstantiation instanceof EntityInterface;
    }

    /**
     * The entity passed to `Factory::new($entity)` / `Factory::from($entity)`,
     * or `null` when the factory was not instantiated from an entity.
     *
     * Exposed so {@see \CakephpFixtureFactories\Factory\BaseFactory::withRequiredParents()}
     * can probe specific FK columns via `has()`/`get()` (which see hidden
     * fields), matching the build-time auto-skip path instead of going through
     * `toArray()` (which drops hidden properties).
     *
     * @internal
     *
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function getInstantiationEntity(): ?EntityInterface
    {
        return $this->dataFromInstantiation instanceof EntityInterface
            ? $this->dataFromInstantiation
            : null;
    }

    /**
     * Data passed in the instantiation by callable.
     *
     * The callable is stored as-is and invoked once per build iteration during
     * compilation. Previously this method invoked the callable eagerly to
     * "type-check" its return value, which (a) caused side-effecting closures
     * to fire one extra time and (b) silently discarded the callable when it
     * returned a non-array. Validation now happens at compile time where it
     * can produce a useful error.
     *
     * @param callable $fn Injected callable
     *
     * @return void
     */
    public function collectArrayFromCallable(callable $fn): void
    {
        $this->dataFromInstantiation = $fn;
    }

    /**
     * @param array<string, mixed> $data Collected data
     *
     * @return void
     */
    public function collectFromPatch(array $data): void
    {
        $this->dataFromPatch = array_merge($this->dataFromPatch, $data);
    }

    /**
     * @param array<int, \Cake\Datasource\EntityInterface|callable|array<string, mixed>> $states Sequence states
     *
     * @return void
     */
    public function collectSequence(array $states): void
    {
        $this->sequenceData = $states;
    }

    /**
     * Register a per-field value cycle. Independent fields cycle at their own
     * periods during compilation; calling this twice for the same field
     * replaces that field's cycle (last-write-wins per field, additive across
     * different fields). Composes with {@see self::collectSequence()}.
     *
     * @param string $field Column to cycle.
     * @param array<array-key, mixed> $values Values cycled by `index % count`.
     *
     * @return void
     */
    public function collectFieldSequence(string $field, array $values): void
    {
        $this->fieldSequences[$field] = array_values($values);
    }

    /**
     * @param int $sequenceIndex Current sequence index
     *
     * @return void
     */
    public function setSequenceIndex(int $sequenceIndex): void
    {
        $this->sequenceIndex = $sequenceIndex;
    }

    /**
     * @param callable $fn Collected data from default template
     *
     * @return void
     */
    public function collectFromDefaultTemplate(callable $fn): void
    {
        $this->dataFromDefaultTemplate = $fn(...);
    }

    /**
     * @param string $associationName Association name
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Collected factory
     * @param bool $isToOne is the association a toOne
     *
     * @return void
     */
    public function collectAssociation(string $associationName, BaseFactory $factory, bool $isToOne): void
    {
        // Only the toOne path layers data from the instantiation array onto
        // the associated factory here. ToMany associations are merged later in
        // mergeWithToMany() based on the resolved property of the relation.
        if ($isToOne) {
            $associationFieldName = $this->getAssociationPropertyName($associationName);
            if (
                $this->dataFromInstantiation instanceof EntityInterface &&
                $this->dataFromInstantiation->has($associationFieldName)
            ) {
                $factory = $factory->state($this->dataFromInstantiation->get($associationFieldName));
            } elseif (
                is_array($this->dataFromInstantiation) &&
                isset($this->dataFromInstantiation[$associationFieldName])
            ) {
                $factory = $factory->state($this->dataFromInstantiation[$associationFieldName]);
            }
        }
        if (isset($this->dataFromAssociations[$associationName])) {
            $this->dataFromAssociations[$associationName][] = $factory;
        } else {
            $this->dataFromAssociations[$associationName] = [$factory];
        }
    }

    /**
     * Resolve the entity property name for the given association.
     *
     * Honors a custom `propertyName` declared on the association (e.g.
     * `belongsTo('Country', ['propertyName' => 'native_country'])`) and falls
     * back to the inflected default when the association cannot be resolved
     * (e.g. non-Cake apps where the table object has no associations).
     *
     * @param string $associationName Association alias, optionally bracketed.
     */
    private function getAssociationPropertyName(string $associationName): string
    {
        try {
            return $this->getFactory()->getTable()->getAssociation($associationName)->getProperty();
        } catch (InvalidArgumentException) {
            return Inflector::underscore(Inflector::singularize($associationName));
        }
    }

    /**
     * Scan for the data stored in the $association path provided and drop it
     *
     * @param string $associationName Association name
     *
     * @return void
     */
    public function dropAssociation(string $associationName): void
    {
        unset($this->dataFromAssociations[$associationName]);
        unset($this->dataFromDefaultAssociations[$associationName]);
    }

    /**
     * Apply a transformation to every stored association factory while preserving
     * the cardinality already collected for each association name.
     *
     * @param callable(\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>): \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $callback Mapper
     *
     * @return void
     */
    public function mapAssociationFactories(callable $callback): void
    {
        foreach ($this->dataFromAssociations as $associationName => $associationFactories) {
            $this->dataFromAssociations[$associationName] = array_map($callback, $associationFactories);
        }

        foreach ($this->dataFromDefaultAssociations as $associationName => $associationFactories) {
            $this->dataFromDefaultAssociations[$associationName] = array_map($callback, $associationFactories);
        }
    }

    /**
     * Populate the factored entity
     *
     * @return \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface>
     */
    public function getCompiledTemplateData(): EntityInterface|array
    {
        $setPrimaryKey = $this->isInPersistMode();

        if (is_array($this->dataFromInstantiation) && isset($this->dataFromInstantiation[0])) {
            $compiledTemplateData = [];
            foreach ($this->dataFromInstantiation as $data) {
                if ($data instanceof BaseFactory) {
                    foreach ($data->buildMany() as $subEntity) {
                        $compiledTemplateData[] = $this->compileEntity($subEntity, $setPrimaryKey);
                        $setPrimaryKey = false;
                    }
                } else {
                    $compiledTemplateData[] = $this->compileEntity($data, $setPrimaryKey);
                    // Only the first entity gets its primary key set.
                    $setPrimaryKey = false;
                }
            }
        } else {
            $compiledTemplateData = $this->compileEntity($this->dataFromInstantiation, $setPrimaryKey);
        }

        return $compiledTemplateData;
    }

    /**
     * @param \Cake\Datasource\EntityInterface|callable|array<string, mixed>|string $injectedData Data from the injection.
     * @param bool $setPrimaryKey Set the primary key if this entity is alone or the first of an array.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function compileEntity(
        array|callable|EntityInterface|string $injectedData = [],
        bool $setPrimaryKey = false,
    ): EntityInterface {
        if (is_string($injectedData)) {
            $injectedData = $this->setDisplayFieldToInjectedString($injectedData);
        }
        $isEntityInjected = $injectedData instanceof EntityInterface;
        if ($isEntityInjected) {
            /** @var \Cake\Datasource\EntityInterface $entity */
            $entity = $injectedData;
        } else {
            $entity = $this->getEntityFromDefaultTemplate();
            $this->mergeWithInjectedData($entity, $injectedData);
        }

        $this->mergeWithSequenceData($entity)
            ->mergeWithPatchedData($entity)
            ->mergeWithAssociatedData($entity, $isEntityInjected);

        if ($this->isInPersistMode() && $this->getModifiedUniqueFields()) {
            $entity->set(self::MODIFIED_UNIQUE_PROPERTIES, $this->getModifiedUniqueFields());
        }
        if ($isEntityInjected && $this->isInPersistMode()) {
            $this->markEntityDirtyIfNew($entity);
        }

        if ($setPrimaryKey && $this->setPrimaryKey) {
            $this->setPrimaryKey($entity);
        }

        return $entity;
    }

    /**
     * Helper method to patch entities with the data compiler data.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to patch.
     * @param array<string, mixed> $data Data to patch.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    private function patchEntity(EntityInterface $entity, array $data): EntityInterface
    {
        $data = $this->setDataWithoutSetters($entity, $data);
        if (!$data) {
            return $entity;
        }

        $data = $this->castArrayNotation($entity, $data);

        return $this->getFactory()->getTable()->patchEntity(
            $entity,
            $data,
            $this->getFactory()->getMarshallerOptions(),
        );
    }

    /**
     * Detect if a field.subvalue is found in the patch data.
     * If so, merge recursively with the existing data
     *
     * @param \Cake\Datasource\EntityInterface $entity entity to patch
     * @param array<string, mixed> $data data to patch
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException if an array notation is merged with a string, or a non array
     *
     * @return array<string, mixed>
     */
    private function castArrayNotation(EntityInterface $entity, array $data): array
    {
        /** @var array<string|int, mixed> $accumulated Tracks merged values per root key */
        $accumulated = [];
        foreach ($data as $key => $value) {
            if (!str_contains($key, '.')) {
                continue;
            }
            $subData = Hash::expand([$key => $value]);
            /** @var string|null $rootKey */
            $rootKey = array_key_first($subData);
            if ($rootKey === null) {
                continue;
            }
            $entityValue = $accumulated[$rootKey] ?? $entity->get((string)$rootKey) ?? [];
            if (!is_array($entityValue)) {
                throw new FixtureFactoryException(sprintf(
                    'Value `%s` cannot be merged with array notation `%s => %s`',
                    var_export($entityValue, true),
                    $key,
                    var_export($value, true),
                ));
            }
            $data[$rootKey] = $accumulated[$rootKey] = array_replace_recursive($entityValue, $subData[$rootKey]);
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * When injecting a string as data, the compiler should understand that this is the value that
     * should be assigned to the display field of the table.
     *
     * @param string $data data injected
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException if the display field of the factory's table is not a string
     *
     * @return array<string>
     */
    private function setDisplayFieldToInjectedString(string $data): array
    {
        $displayField = $this->getFactory()->getTable()->getDisplayField();
        if (is_string($displayField)) {
            return [$displayField => $data];
        }

        $factory = get_class($this->getFactory());
        $table = get_class($this->getFactory()->getTable());

        throw new FixtureFactoryException(
            'The display field of a table must be a string when injecting a string into its factory. '
            . "You injected `$data` in `$factory` but `$table`'s display field is not a string.",
        );
    }

    /**
     * Sets fields individually skipping the setters.
     * CakePHP does not offer to skipp setters on a patchEntity/newEntity
     * Therefore fields which skipped setters should be set individually,
     * and removed from the data patched.
     *
     * @param \Cake\Datasource\EntityInterface $entity entity build
     * @param array<string, mixed> $data data to set
     *
     * @return array<string, mixed> Data without the fields for which the setters are ignored
     */
    private function setDataWithoutSetters(EntityInterface $entity, array $data): array
    {
        foreach ($data as $field => $value) {
            if (in_array($field, $this->skippedSetters)) {
                $entity->set($field, $value, ['setter' => false]);
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Step 1: Create an entity from the default template.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    private function getEntityFromDefaultTemplate(): EntityInterface
    {
        $data = $this->dataFromDefaultTemplate;
        if (is_callable($data)) {
            $data = $data($this->getFactory()->getGenerator());
        }
        $entityClassName = $this->getFactory()->getTable()->getEntityClass();
        $entity = new $entityClassName([], ['source' => $this->getFactory()->getTable()->getAlias()]);

        return $this->patchEntity($entity, $data);
    }

    /**
     * Step 2:
     * Merge with the data injected during the instantiation of the Factory.
     *
     * EntityInterface input is handled separately in {@see compileEntity()} and
     * never reaches this method. By the time we get here, $data is always an
     * array (possibly produced by invoking the original callable).
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to manipulate.
     * @param callable|array<string, mixed> $data Data from the instantiation.
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     *
     * @return $this
     */
    private function mergeWithInjectedData(EntityInterface $entity, array|callable $data)
    {
        if (is_callable($data)) {
            $data = $data(
                $this->getFactory(),
                $this->getFactory()->getGenerator(),
            );
            if (!is_array($data)) {
                throw new FixtureFactoryException(
                    'A callable passed to a factory must return an array; got `'
                    . get_debug_type($data) . '`',
                );
            }
        }
        $this->addEnforcedFields($data);
        $this->patchEntity($entity, $data);

        return $this;
    }

    /**
     * Step 3:
     * Merge with the data gathered by patching.
     * At this point, the developer all the data
     * modified by the user is known ("enforced fields").
     * This will be passed as field to the dedicated table's
     * beforeFind in order to handle the uniqueness of its fields.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to manipulate.
     *
     * @return $this
     */
    private function mergeWithPatchedData(EntityInterface $entity)
    {
        $this->patchEntity($entity, $this->dataFromPatch);
        $this->addEnforcedFields($this->dataFromPatch);

        return $this;
    }

    /**
     * Step 3.5:
     * Merge with the data gathered by sequence().
     *
     * Field-level cycles registered via sequenceField() are applied AFTER the
     * row-level sequence so that for any column appearing in both, the
     * per-field overlay wins. Each field cycles independently at its own
     * period (`index % count(values)`), so independent fields with different
     * cardinalities compose without interfering.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to manipulate.
     *
     * @return $this
     */
    private function mergeWithSequenceData(EntityInterface $entity)
    {
        if ($this->sequenceData) {
            $state = $this->sequenceData[$this->sequenceIndex % count($this->sequenceData)];
            if (is_callable($state)) {
                $factory = $this->getFactory();
                $state = $state(new Sequence(
                    index: $this->sequenceIndex,
                    total: $factory->getTimes(),
                    factory: $factory,
                    generator: $factory->getGenerator(),
                ));
            } elseif ($state instanceof EntityInterface) {
                $state = $state->toArray();
            }

            $this->patchEntity($entity, $state);
            $this->addEnforcedFields($state);
        }

        if ($this->fieldSequences) {
            $fieldState = [];
            foreach ($this->fieldSequences as $field => $values) {
                $fieldState[$field] = $values[$this->sequenceIndex % count($values)];
            }
            $this->patchEntity($entity, $fieldState);
            $this->addEnforcedFields($fieldState);
        }

        return $this;
    }

    /**
     * Step 4:
     * Merge with the data from the associations
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity produced by the factory.
     * @param bool $isEntityInjected Whether \Cake\Datasource\EntityInterface is injected or not.
     *
     * @return $this
     */
    private function mergeWithAssociatedData(EntityInterface $entity, bool $isEntityInjected)
    {
        if ($isEntityInjected) {
            $associatedData = $this->dataFromAssociations;
        } else {
            $defaultAssociations = $this->skipComposedAssociationsWithExplicitForeignKey(
                $entity,
                $this->dataFromDefaultAssociations,
            );
            // Overwrite the default associations if these are found in the associations
            $associatedData = array_merge($defaultAssociations, $this->dataFromAssociations);
        }

        foreach ($associatedData as $propertyName => $data) {
            $association = $this->getAssociationByPropertyName($propertyName);
            $propertyName = $this->getMarshallerAssociationName($propertyName);
            if ($association instanceof HasOne || $association instanceof BelongsTo) {
                // toOne associated data must be singular when saved
                $this->mergeWithToOne($entity, $propertyName, $data, $association);
            } else {
                $this->mergeWithToMany($entity, $propertyName, $data);
            }
        }

        return $this;
    }

    /**
     * Drop `configure()`-composed belongsTo associations whose foreign-key
     * column was set explicitly by the caller.
     *
     * When a factory composes a parent in `configure()` (e.g.
     * `configure()->with('Homes')`) and the caller also pins that parent's FK
     * at the call site — `Factory::new(['home_id' => 123])`,
     * `->setField('home_id', 123)`, `->state(['home_id' => 123])` — the
     * composed parent would be built, persisted, and its fresh id would
     * silently overwrite the explicit `123`. That is almost never the intent:
     * a caller who names the FK wants that exact parent. Auto-skipping the
     * composition (an implicit `->without('Alias')`) lets the explicit FK win
     * and avoids creating a throw-away parent row.
     *
     * Scope and guarantees:
     *  - Only `configure()` defaults are considered. An explicit
     *    `->with('Alias', ...)` lands in `$dataFromAssociations` (merged after
     *    this filter) and always wins — the caller clearly asked for
     *    composition, so it is never auto-skipped.
     *  - The FK must come from caller-supplied state, tracked by
     *    {@see self::getEnforcedFields()} — instantiation array, `state()`,
     *    `setField()`, `sequence()`. Values produced only by `definition()`
     *    defaults never reach the enforced-fields list, so a default FK does
     *    not trigger the skip.
     *  - The resolved FK value on the entity must be non-null. An explicit
     *    `null` is intentionally out of scope: it is indistinguishable here
     *    from "not provided", and the legacy compose-then-overwrite behavior
     *    is preserved for that case. To deliberately build an orphan with a
     *    `null` FK, use an explicit `->without('Alias')` at the call site.
     *  - Behind `FixtureFactories.autoSkipComposeOnExplicitForeignKey`
     *    (default `true`). Set to `false` to restore the legacy behavior where
     *    the composed parent overrides an explicitly-set FK.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity carrying the resolved scalar data.
     * @param array<string, array<int, \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>>> $defaultAssociations Associations composed by configure().
     *
     * @return array<string, array<int, \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>>>
     */
    private function skipComposedAssociationsWithExplicitForeignKey(
        EntityInterface $entity,
        array $defaultAssociations,
    ): array {
        if (!$defaultAssociations) {
            return $defaultAssociations;
        }
        if (!Configure::read('FixtureFactories.autoSkipComposeOnExplicitForeignKey', true)) {
            return $defaultAssociations;
        }

        $enforcedFields = $this->getEnforcedFields();
        if (!$enforcedFields) {
            return $defaultAssociations;
        }

        // Reuse the belongsTo FK→alias enumeration shared with the
        // FK-in-definition() detector (see BaseFactory::collectForeignKeyColumns())
        // as a cheap gate: if the table declares no belongsTo FK columns there
        // is nothing this feature can ever skip.
        if (!BaseFactory::collectForeignKeyColumns($this->getFactory()->getTable())) {
            return $defaultAssociations;
        }
        $enforcedFields = array_fill_keys($enforcedFields, true);

        foreach (array_keys($defaultAssociations) as $associationName) {
            // An explicit ->with('Alias', ...) re-added the same alias: the
            // caller asked for composition, so never auto-skip it.
            if (isset($this->dataFromAssociations[$associationName])) {
                continue;
            }
            $association = $this->getAssociationByPropertyName($associationName);
            if (!$association instanceof BelongsTo) {
                continue;
            }

            // Only skip when the WHOLE foreign key (every component of a
            // composite key) was explicitly provided and resolves to a
            // non-null value — otherwise composing the parent would still be
            // the only way to populate the missing key parts.
            $allExplicit = null;
            foreach ((array)$association->getForeignKey() as $foreignKey) {
                if (!is_string($foreignKey) || $foreignKey === '') {
                    continue;
                }
                if (
                    !isset($enforcedFields[$foreignKey])
                    || !$entity->has($foreignKey)
                    || $entity->get($foreignKey) === null
                ) {
                    $allExplicit = false;

                    break;
                }
                $allExplicit = true;
            }
            if ($allExplicit === true) {
                $this->warnOnAutoSkippedConfigureAssociation($associationName, $association);
                unset($defaultAssociations[$associationName]);
            }
        }

        return $defaultAssociations;
    }

    /**
     * Emit an opt-in warning when a `configure()`-default belongsTo
     * association is auto-skipped because the caller explicitly pinned the
     * FK. This makes "the factory default is fighting caller intent" visible
     * without changing the behavior.
     *
     * @param string $associationName Property/association name from the
     * default-associations map.
     * @param \Cake\ORM\Association\BelongsTo<\Cake\ORM\Table> $association Resolved association.
     *
     * @return void
     */
    private function warnOnAutoSkippedConfigureAssociation(string $associationName, BelongsTo $association): void
    {
        if (!Configure::read('FixtureFactories.warnOnAutoSkippedConfigureAssociation', false)) {
            return;
        }

        $foreignKeys = array_values(array_filter(
            (array)$association->getForeignKey(),
            'is_string',
        ));

        $factoryClass = $this->getFactory()::class;
        $dedupeKey = $factoryClass . '::' . $associationName . '::' . implode(',', $foreignKeys);
        if (isset(self::$reportedAutoSkippedConfigureAssociations[$dedupeKey])) {
            return;
        }
        self::$reportedAutoSkippedConfigureAssociations[$dedupeKey] = true;

        trigger_error(sprintf(
            '%s skipped configure()-default association "%s" because caller-supplied state explicitly set %s. '
            . 'The explicit FK wins and the default compose is not applied for this build. '
            . 'If this warning appears often, the factory may be doing too much in configure(); '
            . 'prefer a lighter default and explicit ->with() / helper composition at the call site.',
            $factoryClass,
            $association->getName(),
            implode(', ', array_map(static fn (string $foreignKey): string => '"' . $foreignKey . '"', $foreignKeys)),
        ), E_USER_WARNING);
    }

    /**
     * Reset the auto-skip warning dedupe between tests.
     *
     * @return void
     */
    public static function resetAutoSkippedConfigureAssociationWarnings(): void
    {
        self::$reportedAutoSkippedConfigureAssociations = [];
    }

    /**
     * There might be several data feeding a toOne relation
     * One reason can be the default template value.
     * Here the latest inserted record is taken
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity produced by the factory.
     * @param string $associationName Association
     * @param array<int, \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $data Data to inject
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     *
     * @return void
     */

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity produced by the factory.
     * @param string $associationName Association property name.
     * @param array<int, \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $data Data to inject
     * @param \Cake\ORM\Association|null $association Resolved association (used for recycle target-table lookup).
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     */
    private function mergeWithToOne(
        EntityInterface $entity,
        string $associationName,
        array $data,
        ?Association $association = null,
    ): void {
        $count = count($data);
        /** @var \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory */
        $factory = $data[$count - 1];

        $recycles = $this->factory->getRecycledEntities();
        $associatedEntity = null;
        // Recycle substitutes only when this branch's active (last-written)
        // factory has no explicit customization. A user who wrote
        // `with('Alias', $entity)` or `with('Alias', Factory::new()->forX())`
        // has expressed per-branch intent that should win over recycle.
        //
        // The post-buildMany cardinality check below is intentionally skipped on
        // the recycle fast path: the user supplied a specific entity to reuse, so
        // the original factory's build (and its afterBuild callbacks) are no longer
        // expected to fire. AssociationBuilder already rejects `->count(N)` on
        // to-one branches at registration time, which is the common-case guard.
        if (
            $recycles !== []
            && $association instanceof BelongsTo
            && !$factory->isInstantiatedFromEntity()
            && !$factory->hasUserSetAssociations()
        ) {
            $associatedEntity = self::matchRecycledEntity($association, $recycles);
        }

        if ($associatedEntity === null) {
            if ($recycles !== []) {
                $factory = $factory->inheritRecycledEntities($recycles);
            }
            $associatedEntities = $factory->buildMany();
            if (count($associatedEntities) !== 1) {
                throw new FixtureFactoryException(sprintf(
                    'Association `%s` expects exactly 1 entity, but `%s` produced %d. Use a singular factory for to-one associations.',
                    $associationName,
                    $factory::class,
                    count($associatedEntities),
                ));
            }
            $associatedEntity = $associatedEntities[0];
        }

        // A `foreignKey => false` belongsTo cannot go through Cake's standard
        // cascade: `BelongsTo::saveAssociated()` does
        //   array_combine((array)false, $target->extract((array)$bindingKey))
        // which becomes `['' => value]` (false cast to '') and the subsequent
        // `$entity->patch('', ...)` throws "Cannot set an empty field". There
        // is no FK column to populate anyway — the relation is queried by
        // custom conditions at read time. Save the parent independently and
        // skip the property-set that would trigger the broken cascade.
        if (
            $association instanceof BelongsTo
            && $association->getForeignKey() === false
            && $this->isInPersistMode()
        ) {
            if ($associatedEntity->isNew()) {
                // Join the active test transaction so this save participates
                // in the same rollback unit as the root save — orphan-free on
                // root failure. No-op when no test strategy is active (this
                // plugin's contract is test-suite use with the eager strategy).
                FactoryTransactionStrategy::getActiveInstance()
                    ?->ensureTransaction($association->getTarget()->getConnection());

                // Match the nested-save semantics the cascade path applies:
                // tag the entity so FactoryTableBeforeSave treats it as
                // associated (unique-row reuse etc.), mark new entities
                // dirty, and let Cake cascade through the parent's own
                // associations normally — only THIS belongsTo edge is bypassed.
                $associatedEntity->set(self::IS_ASSOCIATED, true);
                $this->markEntityDirtyIfNew($associatedEntity);
                $target = $association->getTarget();
                // Mirror BaseFactory::doPersist(): the tracker drives
                // teardown/truncation and diagnostics, so this independent
                // save must register too.
                FactoryTableTracker::getInstance()
                    ->trackTable($target);
                // Use the factory's own save options (checkRules / atomic /
                // associated list) so this persist is configured the same
                // way as the cascade path would have configured it.
                $target->saveOrFail(
                    $associatedEntity,
                    $factory->getSaveOptionsForAssociated(),
                );
                // Finalize: Cake 5.4+ defers clean()/setNew(false) until the
                // outer transaction closes; the cascade path's
                // finalizePersistedEntities() compensates, mirror it here so
                // afterSave callbacks observe a consistent post-save shape.
                if ($target->getConnection()->inTransaction()) {
                    $associatedEntity->clean();
                    $associatedEntity->setNew(false);
                    $associatedEntity->setSource($target->getAlias());
                }
                // Replay this branch's factory afterSave callbacks AND the
                // afterSave events/callbacks for every NESTED associated
                // factory under it. Since the parent is not attached to the
                // root, replayAssociatedAfterSaveEvents() on the root can't
                // discover the subtree — replay it here instead.
                $associatedEntity = $factory->replayAssociatedAfterSaveForTree($associatedEntity);
                $associatedEntity = $factory->applyAfterSaveCallbacksToEntity($associatedEntity);
            }

            // NOTE: we intentionally do NOT call $entity->set($associationName,
            // $associatedEntity) here. Doing so would (re-)trigger Cake's
            // BelongsTo::saveAssociated() on the root save and reproduce the
            // exact "Cannot set an empty field" crash this branch exists to
            // avoid. Trade-off: the composed parent is persisted and queryable
            // via custom conditions, but is NOT attached to the in-memory root
            // entity post-save. A root afterSave() callback that expects to
            // read `$root->{property}` for a foreignKey => false parent must
            // re-query it instead.
            return;
        }

        if ($this->isInPersistMode()) {
            $associatedEntity->set(self::IS_ASSOCIATED, true);
            $this->markEntityDirtyIfNew($associatedEntity);
        }

        $entity->set($associationName, $associatedEntity);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity produced by the factory.
     * @param string $associationName Association
     * @param array<int, \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $data Data to inject
     *
     * @return void
     */
    private function mergeWithToMany(EntityInterface $entity, string $associationName, array $data): void
    {
        $associationData = $entity->get($associationName);
        $recycles = $this->factory->getRecycledEntities();
        foreach ($data as $factory) {
            if ($recycles !== []) {
                $factory = $factory->inheritRecycledEntities($recycles);
            }
            if (!$associationData) {
                $associationData = $this->getManyEntities($factory);
            } else {
                $associationData = array_merge($associationData, $this->getManyEntities($factory));
            }
        }
        $entity->set($associationName, $associationData);
    }

    /**
     * Normalize a registry alias / class name to a canonical recycle key.
     *
     * Stripped:
     *  - FQCN namespace (`App\Model\Table\AddressesTable` → `AddressesTable`)
     *  - The `Table` suffix on bare table class names (`AddressesTable` → `Addresses`)
     *  - The factory-internal `__ff_<hash>` suffix added by FactoryTableLocator
     *  - The `Plugin.` prefix on plugin-namespaced table identifiers — mirrors
     *    `FactoryTableLocator`, which already strips that prefix from the
     *    registry aliases it generates for plugin tables; both sides of the
     *    recycle map must agree to match.
     *
     * Examples — all normalize to `Addresses`:
     *  - `Addresses`
     *  - `Addresses__ff_e0b429e1`
     *  - `TestPlugin.Addresses`
     *  - `App\Model\Table\AddressesTable`
     *
     * @internal
     */
    public static function normalizeTableAlias(string $alias): string
    {
        // Strip FQCN namespace.
        $pos = strrpos($alias, '\\');
        if ($pos !== false) {
            $alias = substr($alias, $pos + 1);
        }
        // Strip the `Table` suffix on bare class names (but not the literal `Table`).
        if ($alias !== 'Table' && str_ends_with($alias, 'Table')) {
            $alias = substr($alias, 0, -5);
        }
        // Strip the plugin prefix (`Plugin.Addresses` → `Addresses`) to match
        // what FactoryTableLocator does with plugin-namespaced registry aliases.
        $pos = strrpos($alias, '.');
        if ($pos !== false) {
            $alias = substr($alias, $pos + 1);
        }
        // Strip the factory-internal suffix.
        $pos = strpos($alias, '__ff_');
        if ($pos !== false) {
            $alias = substr($alias, 0, $pos);
        }

        return $alias;
    }

    /**
     * Match a registered belongsTo association against a recycle map.
     *
     * Tries both `$association->getClassName()` (the canonical target table)
     * and `$association->getName()` (the association alias) so a recycle works
     * whether the entity was built through a factory (source = canonical table)
     * or loaded through an aliased belongsTo (source = association alias, e.g.
     * `$author->business_address` whose `getSource()` returns `'BusinessAddress'`).
     *
     * @param \Cake\ORM\Association $association BelongsTo association to look up.
     * @param array<string, \Cake\Datasource\EntityInterface> $recycles Recycle map keyed by normalized table name.
     *
     * @return \Cake\Datasource\EntityInterface|null Matched entity, or null when no match.
     */
    private static function matchRecycledEntity(Association $association, array $recycles): ?EntityInterface
    {
        $candidates = [
            self::normalizeTableAlias($association->getClassName()),
            self::normalizeTableAlias($association->getName()),
        ];
        foreach (array_unique($candidates) as $key) {
            if (isset($recycles[$key])) {
                return $recycles[$key];
            }
        }

        return null;
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     *
     * @return array<\Cake\Datasource\EntityInterface>
     */
    private function getManyEntities(BaseFactory $factory): array
    {
        $entities = $factory->buildMany();
        if ($this->isInPersistMode()) {
            foreach ($entities as $entity) {
                $entity->set(self::IS_ASSOCIATED, true);
                $this->markEntityDirtyIfNew($entity);
            }
        }

        return $entities;
    }

    /**
     * Ensure new associated entities are marked dirty so CakePHP will save them.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to mark.
     * @param array<int, bool> $visited
     *
     * @return void
     */
    private function markEntityDirtyIfNew(EntityInterface $entity, array &$visited = []): void
    {
        $entityId = spl_object_id($entity);
        if (isset($visited[$entityId])) {
            return;
        }
        $visited[$entityId] = true;

        if (!$entity->isNew()) {
            return;
        }

        // Exclude virtual fields: they aren't backed by schema columns, so
        // marking them dirty has no save effect and just confuses isDirty()
        // callers downstream.
        $fields = array_diff(array_keys($entity->toArray()), $entity->getVirtual());
        if (!$entity->isDirty()) {
            foreach ($fields as $field) {
                $entity->setDirty($field, true);
            }
        }

        foreach ($fields as $field) {
            $value = $entity->get($field);
            if ($value instanceof EntityInterface) {
                $this->markEntityDirtyIfNew($value, $visited);

                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if ($item instanceof EntityInterface) {
                    $this->markEntityDirtyIfNew($item, $visited);
                }
            }
        }
    }

    /**
     * Used in the Factory make in order to distinguish default associations
     * from conscious associations.
     *
     * Anything still in `$dataFromAssociations` at the end of `setUp()` was
     * contributed by `configure()` and is, by definition, a factory default
     * — promote it. Anything already in `$dataFromDefaultAssociations` was
     * placed there *during* `configure()` (e.g. via
     * {@see self::demoteAssociationToDefault()} when `configure()` ran
     * `$this->withRequiredParents()`); those entries must be preserved.
     *
     * Use a left-priority union (`+`) so a user-set alias still wins over a
     * pre-existing default for the same alias, matching the original
     * "explicit beats default" semantic.
     *
     * @return void
     */
    public function collectAssociationsFromDefaultTemplate(): void
    {
        $this->dataFromDefaultAssociations = $this->dataFromAssociations + $this->dataFromDefaultAssociations;
        $this->dataFromAssociations = [];
    }

    /**
     * Reclassify a single association from an explicit user `with()`
     * (`$dataFromAssociations`) to a `configure()`-style default
     * (`$dataFromDefaultAssociations`).
     *
     * `withRequiredParents()` composes the required chain by calling
     * `with()` *after* `configure()` has been snapshotted, so its
     * auto-resolved parents would otherwise look like per-branch user intent
     * and flip {@see self::hasUserSetAssociations()} — which makes
     * {@see self::mergeWithToOne()} refuse to substitute a `recycle()`d row
     * for that node. Auto-composition is not user intent, so demote it: the
     * parent is still built, but recycle substitution works at every depth of
     * the chain, exactly like a `configure()` default.
     *
     * No-op when the alias is not currently an explicit association.
     *
     * @internal
     *
     * @param string $associationName Association alias to reclassify.
     *
     * @return void
     */
    public function demoteAssociationToDefault(string $associationName): void
    {
        if (!isset($this->dataFromAssociations[$associationName])) {
            return;
        }

        $this->dataFromDefaultAssociations[$associationName] = $this->dataFromAssociations[$associationName];
        unset($this->dataFromAssociations[$associationName]);
    }

    /**
     * Whether the user (post-`configure()`) added any association overrides
     * via `with()` / `for()` / `has()` on this factory.
     *
     * Distinguishes user customizations from `configure()` defaults: defaults
     * end up in `$dataFromDefaultAssociations` after
     * {@see self::collectAssociationsFromDefaultTemplate()}, while explicit
     * user calls populate `$dataFromAssociations`.
     *
     * @internal
     */
    public function hasUserSetAssociations(): bool
    {
        return $this->dataFromAssociations !== [];
    }

    /**
     * Returns the property name of the association. This can be dot separated for deep associations
     * Throws an exception if the association name does not exist on the rootTable of the factory
     *
     * @param string $associationName Association
     *
     * @return string underscore_version of the input string
     */
    public function getMarshallerAssociationName(string $associationName): string
    {
        $result = [];
        $cast = explode('.', $associationName);
        $table = $this->getFactory()->getTable();
        foreach ($cast as $ass) {
            $association = $table->getAssociation($ass);
            $result[] = $association->getProperty();
            $table = $association->getTarget();
        }

        return implode('.', $result);
    }

    /**
     * @param string $propertyName Property
     *
     * @return \Cake\ORM\Association|bool
     */
    public function getAssociationByPropertyName(string $propertyName): bool|Association
    {
        try {
            return $this->getFactory()->getTable()->getAssociation(Inflector::camelize($propertyName));
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity to manipulate.
     *
     * @return \Cake\Datasource\EntityInterface
     */
    public function setPrimaryKey(EntityInterface $entity): EntityInterface
    {
        // A set of primary keys is produced if in persistence mode, and if a first set was not produced yet
        if (!$this->isInPersistMode() || !is_array($this->primaryKeyOffset)) {
            return $entity;
        }

        foreach ($this->createPrimaryKeyOffset() as $pk => $value) {
            if (!$entity->has($pk)) {
                $entity->set($pk, $value);
            }
        }

        return $entity;
    }

    /**
     * @throws \CakephpFixtureFactories\Error\PersistenceException
     *
     * @return array<string, int|string>
     */
    public function createPrimaryKeyOffset(): array
    {
        if (!is_array($this->primaryKeyOffset)) {
            throw new PersistenceException('A set of primary keys was already created');
        }
        $res = empty($this->primaryKeyOffset) ? $this->generateArrayOfRandomPrimaryKeys() : $this->primaryKeyOffset;

        $this->updatePostgresSequence($res);

        // Set to null, this factory will never generate a primaryKeyOffset again
        $this->primaryKeyOffset = null;

        return $res;
    }

    /**
     * @return array<string, int|string>
     */
    public function generateArrayOfRandomPrimaryKeys(): array
    {
        $primaryKeys = (array)$this->getFactory()->getTable()->getPrimaryKey();
        $res = [];
        foreach ($primaryKeys as $pk) {
            $res[$pk] = $this->generateRandomPrimaryKey(
                (string)$this->getFactory()->getTable()->getSchema()->getColumnType($pk),
            );
        }

        return $res;
    }

    /**
     * Credits to Faker
     * https://github.com/fzaninotto/Faker/blob/master/src/Faker/ORM/CakePHP/ColumnTypeGuesser.php
     *
     * @param string $columnType Column type
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     *
     * @return string|int
     */
    public function generateRandomPrimaryKey(string $columnType): int|string
    {
        return match ($columnType) {
            'uuid', 'string' => $this->getFactory()->getGenerator()->uuid(),
            'tinyinteger' => random_int(0, 127),
            'smallinteger' => random_int(0, 32767),
            'mediuminteger' => random_int(0, 8388607),
            // mt_rand() is capped at mt_getrandmax() (typically 2^31 - 1),
            // which silently truncates biginteger PKs to 32-bit range.
            'biginteger' => random_int(0, PHP_INT_MAX),
            'integer' => random_int(0, 2147483647),
            default => throw new FixtureFactoryException(sprintf(
                'Cannot generate a random primary key for column type `%s`. '
                . 'Provide an explicit primary key offset via setPrimaryKeyOffset().',
                $columnType,
            )),
        };
    }

    /**
     * @return \CakephpFixtureFactories\Factory\BaseFactory<TEntity>
     */
    public function getFactory(): BaseFactory
    {
        return $this->factory;
    }

    /**
     * @param mixed $primaryKeyOffset Name of the primary key
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     *
     * @return void
     */
    public function setPrimaryKeyOffset(mixed $primaryKeyOffset): void
    {
        if (is_int($primaryKeyOffset) || is_string($primaryKeyOffset)) {
            $primaryKey = $this->getFactory()->getTable()->getPrimaryKey();
            if (!is_string($primaryKey)) {
                throw new FixtureFactoryException(
                    "The primary key assigned must be a string as `$primaryKeyOffset` is a string or an integer.",
                );
            }
            $this->primaryKeyOffset = [
                $primaryKey => $primaryKeyOffset,
            ];
        } elseif (is_array($primaryKeyOffset)) {
            $this->primaryKeyOffset = $primaryKeyOffset;
        } else {
            throw new FixtureFactoryException(
                "`$primaryKeyOffset` must be an integer, a string or an array of format ['primaryKey1' => value, ...]",
            );
        }
    }

    /**
     * @return void
     */
    public function disablePrimaryKeyOffset(): void
    {
        $this->setPrimaryKey = false;
    }

    /**
     * @param array<string, int|string> $primaryKeys Set of primary keys
     *
     * @return void
     */
    private function updatePostgresSequence(array $primaryKeys): void
    {
        $table = $this->getFactory()->getTable();
        // Use instanceof on the actual driver instance — comparing the class
        // name string with === would miss Postgres subclasses.
        if ($table->getConnection()->getDriver() instanceof Postgres) {
            $tableName = $table->getTable();
            $connection = $table->getConnection();

            foreach ($primaryKeys as $pk => $offset) {
                $result = $connection->execute(
                    'SELECT pg_get_serial_sequence(?, ?)',
                    [$tableName, $pk],
                )->fetchAll();
                $seq = $result[0][0] ?? null;
                if ($seq !== null) {
                    $connection->execute(
                        'SELECT setval(?, ?)',
                        [$seq, $offset],
                    );
                }
            }
        }
    }

    /**
     * Fetch the fields that were intentionally modified by the developer
     * and that are unique. These should be watched for uniqueness.
     *
     * @return array<string>
     */
    public function getModifiedUniqueFields(): array
    {
        return array_values(
            array_intersect(
                $this->getEnforcedFields(),
                array_merge(
                    $this->getFactory()->getUniqueProperties(),
                    (array)$this->getFactory()->getTable()->getPrimaryKey(),
                ),
            ),
        );
    }

    /**
     * @return bool
     */
    public function isInPersistMode(): bool
    {
        return self::$persistDepth > 0;
    }

    /**
     * Persist mode is intentionally process-wide so nested association builds
     * pick it up, but a naive boolean flip breaks under nested persist calls
     * (e.g. an `afterBuild` callback that persists another factory): the inner
     * `endPersistMode()` flipped the flag off mid-flight for the outer flow.
     * Counting depth keeps the flag set until the outermost persist returns.
     *
     * @return void
     */
    public function startPersistMode(): void
    {
        self::$persistDepth++;
    }

    /**
     * @return void
     */
    public function endPersistMode(): void
    {
        if (self::$persistDepth > 0) {
            self::$persistDepth--;
        }
    }

    /**
     * Reset the process-wide persist-depth counter.
     *
     * Normally startPersistMode()/endPersistMode() balance pair-by-pair. If
     * something throws *between* startPersistMode() and endPersistMode()
     * (e.g. an exception inside an `afterBuild` callback, a constraint
     * violation in the middle of saveMany()), the counter stays incremented.
     * Without a teardown reset, the next test in the same process boots with
     * `isInPersistMode() === true` even at top level — quietly poisoning its
     * association resolution. Called from the transaction strategy's
     * setupTest()/teardownTest() to make the boundary visible.
     *
     * @return void
     */
    public static function resetPersistDepth(): void
    {
        self::$persistDepth = 0;
    }

    /**
     * @return array<string>
     */
    public function getEnforcedFields(): array
    {
        return $this->enforcedFields;
    }

    /**
     * Field name => value pairs the caller has pinned via array instantiation
     * (`Factory::new(['fk' => x])`), `->state([...])`, `->setField()` or
     * `->patchData([...])`, resolvable *before* the build runs.
     *
     * Unlike {@see self::getEnforcedFields()} (populated during the build),
     * this is available at chain-construction time, so
     * {@see \CakephpFixtureFactories\Factory\BaseFactory::withRequiredParents()}
     * can treat a *non-null* pinned FK as "this parent is already satisfied"
     * and not compose it (matching the non-null semantics of the
     * autoSkipComposeOnExplicitForeignKey feature). Callable
     * instantiation/patch data is opaque pre-build and therefore not
     * introspected.
     *
     * Patch data (state/setField/patchData) takes precedence over
     * instantiation data, mirroring the build-time merge order.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function getPinnedFields(): array
    {
        $pinned = [];
        if (is_array($this->dataFromInstantiation)) {
            foreach ($this->dataFromInstantiation as $key => $value) {
                if (is_string($key)) {
                    $pinned[$key] = $value;
                }
            }
        }

        // sequence() / sequenceField() pins are explicit caller state too. A
        // field only counts as pinned when EVERY produced row sets it to a
        // non-null value (present in every sequence state / every cycle
        // value) — otherwise some row would still need the parent composed.
        // Callable states are opaque pre-build and conservatively ignored.
        return $this->dataFromPatch
            + $this->pinnedSequenceFields()
            + $pinned;
    }

    /**
     * Fields pinned to a non-null value for *every* produced row by
     * `sequence()` / `sequenceField()` (and therefore safe to treat as
     * already satisfying a required parent).
     *
     * @return array<string, mixed>
     */
    private function pinnedSequenceFields(): array
    {
        $pinned = [];

        if ($this->sequenceData !== []) {
            $perStateArrays = [];
            foreach ($this->sequenceData as $state) {
                if ($state instanceof EntityInterface) {
                    $perStateArrays[] = $state->toArray();
                } elseif (is_array($state)) {
                    $perStateArrays[] = $state;
                } else {
                    // A callable state is opaque pre-build: cannot guarantee
                    // any field is pinned for that row — bail out entirely.
                    $perStateArrays = [];

                    break;
                }
            }
            if ($perStateArrays !== []) {
                $common = $perStateArrays[0];
                foreach ($perStateArrays as $stateArray) {
                    foreach ($common as $field => $_) {
                        if (
                            !array_key_exists($field, $stateArray)
                            || $stateArray[$field] === null
                        ) {
                            unset($common[$field]);
                        }
                    }
                }
                foreach ($common as $field => $value) {
                    if (is_string($field)) {
                        $pinned[$field] = $value;
                    }
                }
            }
        }

        foreach ($this->fieldSequences as $field => $values) {
            if ($values === []) {
                continue;
            }
            $allNonNull = true;
            foreach ($values as $value) {
                if ($value === null) {
                    $allNonNull = false;

                    break;
                }
            }
            if ($allNonNull) {
                $pinned[$field] = $values[0];
            }
        }

        return $pinned;
    }

    /**
     * When a field is set in the factory instantiation
     * or in a patchData, save the name of the fields that
     * have been set by the user. This is useful for the
     * uniqueness of the fields.
     *
     * @param array<string, mixed> $fields Fields to be marked as enforced.
     *
     * @return void
     */
    public function addEnforcedFields(array $fields): void
    {
        // Dedup so repeated state()/patch calls don't grow the list linearly
        // with redundant entries — each field name appears once.
        $this->enforcedFields = array_values(array_unique(array_merge(
            array_keys($fields),
            $this->enforcedFields,
        )));
    }

    /**
     * Sets the fields which setters should be skipped
     *
     * @param array<string> $skippedSetters setters to skip
     *
     * @return void
     */
    public function setSkippedSetters(array $skippedSetters): void
    {
        $this->skippedSetters = $skippedSetters;
    }
}
