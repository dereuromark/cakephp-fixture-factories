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

use Cake\Database\Driver\Postgres;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Association;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\HasOne;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Error\PersistenceException;
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
                $state = $state(
                    $this->getFactory(),
                    $this->getFactory()->getGenerator(),
                    $this->sequenceIndex,
                );
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
            // Overwrite the default associations if these are found in the associations
            $associatedData = array_merge($this->dataFromDefaultAssociations, $this->dataFromAssociations);
        }

        foreach ($associatedData as $propertyName => $data) {
            $association = $this->getAssociationByPropertyName($propertyName);
            $propertyName = $this->getMarshallerAssociationName($propertyName);
            if ($association instanceof HasOne || $association instanceof BelongsTo) {
                // toOne associated data must be singular when saved
                $this->mergeWithToOne($entity, $propertyName, $data);
            } else {
                $this->mergeWithToMany($entity, $propertyName, $data);
            }
        }

        return $this;
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
    private function mergeWithToOne(EntityInterface $entity, string $associationName, array $data): void
    {
        $count = count($data);
        /** @var \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory */
        $factory = $data[$count - 1];

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
        foreach ($data as $factory) {
            if (!$associationData) {
                $associationData = $this->getManyEntities($factory);
            } else {
                $associationData = array_merge($associationData, $this->getManyEntities($factory));
            }
        }
        $entity->set($associationName, $associationData);
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
     * from conscious associations
     *
     * @return void
     */
    public function collectAssociationsFromDefaultTemplate(): void
    {
        $this->dataFromDefaultAssociations = $this->dataFromAssociations;
        $this->dataFromAssociations = [];
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
     * @return array<string>
     */
    public function getEnforcedFields(): array
    {
        return $this->enforcedFields;
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
