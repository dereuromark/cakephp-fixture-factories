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

use Cake\ORM\Association;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\BelongsToMany;
use Cake\ORM\Association\HasMany;
use Cake\ORM\Association\HasOne;
use Cake\ORM\Table;
use CakephpFixtureFactories\Error\AssociationBuilderException;
use Exception;
use Throwable;

/**
 * Class AssociationBuilder
 *
 * @internal
 */
class AssociationBuilder
{
    use FactoryAwareTrait {
        getFactory as getFactoryInstance;
    }

    /**
     * @var array<\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>>
     */
    private array $associations = [];

    /**
     * @var array<string, mixed>
     */
    private array $manualAssociations = [];

    /**
     * @var \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    private BaseFactory $factory;

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Associated factory
     */
    public function __construct(BaseFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Associated factory
     *
     * @return void
     */
    public function setFactory(BaseFactory $factory): void
    {
        $this->factory = $factory;
    }

    /**
     * Makes sure that a given association is well defined in the
     * builder's factory's table
     *
     * @param string $associationName Name of the association
     *
     * @return \Cake\ORM\Association
     */
    public function getAssociation(string $associationName): Association
    {
        $associationName = $this->removeBrackets($associationName);

        try {
            $association = $this->getTable()->getAssociation($associationName);
        } catch (Exception $e) {
            throw new AssociationBuilderException($e->getMessage(), (int)$e->getCode(), $e);
        }
        if ($this->associationIsToOne($association) || $this->associationIsToMany($association)) {
            return $association;
        }

        $associationType = get_class($association);

        throw new AssociationBuilderException(
            "Unknown association type `$associationType` on table `{$this->getTable()->getAlias()}`",
        );
    }

    /**
     * HasOne and BelongsTo association cannot be multiple
     *
     * @param string $associationName Name of the association
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $associationFactory Factory
     *
     * @throws \CakephpFixtureFactories\Error\AssociationBuilderException
     *
     * @return bool
     */
    public function validateToOneAssociation(string $associationName, BaseFactory $associationFactory): bool
    {
        if ($this->associationIsToOne($this->getAssociation($associationName)) && $associationFactory->getTimes() > 1) {
            throw new AssociationBuilderException(
                "Association `$associationName` on `" . $this->getTable()->getEntityClass() . '` cannot be multiple',
            );
        }

        return true;
    }

    /**
     * @param string $associationName Association name
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $associatedFactory Factory
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    public function removeAssociationForToOneFactory(string $associationName, BaseFactory $associatedFactory): BaseFactory
    {
        $association = $this->getAssociation($associationName);
        if ($this->associationIsToMany($association)) {
            $backAssociation = $this->findBackAssociation($associatedFactory->getTable(), $association);
            if ($backAssociation !== null) {
                return $associatedFactory->without($backAssociation->getName());
            }
        }

        return $associatedFactory;
    }

    /**
     * Normalize the associated factory for to-one relations.
     *
     * @param string $associationName Association name
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $associationFactory Factory
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    public function prepareAssociationFactory(string $associationName, BaseFactory $associationFactory): BaseFactory
    {
        $this->validateToOneAssociation($associationName, $associationFactory);

        return $this->removeAssociationForToOneFactory($associationName, $associationFactory);
    }

    /**
     * Find a belongsTo association on the associated table that targets the current table.
     *
     * @param \Cake\ORM\Table $associatedTable The associated table
     * @param \Cake\ORM\Association $forwardAssociation The forward association from the current table
     *
     * @return \Cake\ORM\Association|null
     */
    private function findBackAssociation(Table $associatedTable, Association $forwardAssociation): ?Association
    {
        $currentTableName = $this->getTable()->getAlias();
        $forwardForeignKey = (array)$forwardAssociation->getForeignKey();
        foreach ($associatedTable->associations() as $association) {
            if (
                $association instanceof BelongsTo &&
                (
                    $association->getClassName() === $currentTableName ||
                    $association->getTarget()->getAlias() === $currentTableName ||
                    $association->getTarget()->getRegistryAlias() === $currentTableName
                ) &&
                (array)$association->getForeignKey() === $forwardForeignKey
            ) {
                return $association;
            }
        }

        return null;
    }

    /**
     * Get the factory for the association
     *
     * @param string $associationName Association name
     * @param mixed $data Injected data
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    public function getAssociatedFactory(
        string $associationName,
        mixed $data = [],
    ): BaseFactory {
        $associations = explode('.', $associationName);
        $firstAssociation = array_shift($associations);

        $times = $this->getTimeBetweenBrackets($firstAssociation);
        $firstAssociation = $this->removeBrackets($firstAssociation);

        $table = $this->getTable()->getAssociation($firstAssociation)->getClassName();

        if ($associations) {
            $factory = $this->getFactoryFromTableName($table);
            $factory = $factory->with(implode('.', $associations), $data);
        } else {
            $factory = $this->getFactoryFromTableName($table, $data);
        }
        if ($times) {
            $factory = $factory->count($times);
        }

        return $factory;
    }

    /**
     * Get a factory from a table name
     *
     * @param string $modelName Model Name
     * @param mixed $data Injected data
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    public function getFactoryFromTableName(string $modelName, mixed $data = []): BaseFactory
    {
        try {
            return $this->getFactoryInstance($modelName, $data);
        } catch (Throwable $e) {
            throw new AssociationBuilderException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Remove the brackets and their content in an 'Association1[i].Association2[j]' formatted string
     *
     * @param string $string String
     *
     * @return string
     */
    public function removeBrackets(string $string): string
    {
        return (string)preg_replace("/\[[^]]+\]/", '', $string);
    }

    /**
     * Return the integer i between brackets in an 'Association[i]' formatted string
     *
     * @param string $string String
     *
     * @throws \CakephpFixtureFactories\Error\AssociationBuilderException
     *
     * @return int|null
     */
    public function getTimeBetweenBrackets(string $string): ?int
    {
        preg_match_all("/\[([^\]]*)\]/", $string, $matches);
        $res = $matches[1];
        if (!$res) {
            return null;
        }
        if (count($res) === 1 && ctype_digit($res[0]) && (int)$res[0] >= 1) {
            return (int)$res[0];
        }

        throw new AssociationBuilderException("Error parsing `$string`.");
    }

    /**
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> Factory
     */
    public function getFactory(): BaseFactory
    {
        return $this->factory;
    }

    /**
     * @param \Cake\ORM\Association $association Association
     *
     * @return bool
     */
    public function associationIsToOne(Association $association): bool
    {
        return $association instanceof HasOne || $association instanceof BelongsTo;
    }

    /**
     * @param \Cake\ORM\Association $association Association
     *
     * @return bool
     */
    public function associationIsToMany(Association $association): bool
    {
        return $association instanceof HasMany || $association instanceof BelongsToMany;
    }

    /**
     * Scan for all associations starting by the $association path provided and drop them
     *
     * @param string $associationName Association name
     *
     * @return void
     */
    public function dropAssociation(string $associationName): void
    {
        $explode = explode('.', $associationName);
        $baseAssociationName = array_shift($explode);
        if (!isset($this->associations[$baseAssociationName])) {
            return;
        }
        if (count($explode) === 0) {
            unset($this->associations[$baseAssociationName]);
        } else {
            $this->associations[$baseAssociationName] = $this->associations[$baseAssociationName]->without(implode('.', $explode));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssociated(): array
    {
        $result = [];
        foreach ($this->associations as $name => $associatedFactory) {
            $result[$name] = $associatedFactory->getMarshallerOptions();
        }

        return array_replace_recursive($result, $this->manualAssociations);
    }

    /**
     * @return array<\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>>
     */
    public function getAssociations(): array
    {
        return $this->associations;
    }

    /**
     * Add an associated factory to the BaseFactory
     *
     * @param string $associationName Association
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     *
     * @return void
     */
    public function addAssociation(string $associationName, BaseFactory $factory): void
    {
        $this->associations[$associationName] = $factory;
    }

    /**
     * @param callable(\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>): \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $callback Mapper
     *
     * @return void
     */
    public function mapAssociations(callable $callback): void
    {
        foreach ($this->associations as $associationName => $associatedFactory) {
            $this->associations[$associationName] = $callback($associatedFactory);
        }
    }

    /**
     * @return \Cake\ORM\Table
     */
    public function getTable(): Table
    {
        return $this->getFactory()->getTable();
    }

    /**
     * @param array<string, mixed> $associations
     *
     * @return void
     */
    public function addManualAssociations(array $associations): void
    {
        $this->manualAssociations = array_replace_recursive($this->manualAssociations, $associations);
    }
}
