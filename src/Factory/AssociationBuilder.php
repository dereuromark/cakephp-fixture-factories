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

use Cake\ORM\Association;
use CakephpFixtureFactories\Error\AssociationBuilderException;
use Exception;

/**
 * AssociationBuilder stub for v3 backward compatibility
 *
 * This class was removed in v4 but is provided as a minimal stub
 * to maintain backward compatibility with existing tests.
 *
 * @deprecated Will be removed in v5
 */
class AssociationBuilder
{
    private BaseFactory $factory;

    public function __construct(BaseFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Get an association from the factory's table
     *
     * @param string $associationName
     * @return \Cake\ORM\Association
     * @throws \CakephpFixtureFactories\Error\AssociationBuilderException
     */
    public function getAssociation(string $associationName): Association
    {
        $table = $this->factory->getTable();

        try {
            return $table->getAssociation($associationName);
        } catch (Exception $e) {
            throw new AssociationBuilderException($e->getMessage());
        }
    }

    /**
     * Associated factory builder - returns the base factory for chaining
     *
     * @param string $associationName
     * @param mixed $data
     * @return \CakephpFixtureFactories\Factory\BaseFactory
     */
    public function getAssociatedFactory(string $associationName, mixed $data = []): BaseFactory
    {
        return $this->factory->withAssoc($associationName, $data);
    }

    /**
     * Get factory from table name - stub for compatibility
     *
     * @param string $tableName
     * @return string|null
     */
    public function getFactoryFromTableName(string $tableName): ?string
    {
        // Try to find factory class for the table
        $modelName = str_replace('Table', '', $tableName);
        $factoryName = $modelName . 'Factory';

        $namespaces = [
            'App\\Test\\Factory\\',
            'CakephpFixtureFactories\\Test\\Factory\\',
            'TestApp\\Test\\Factory\\',
        ];

        foreach ($namespaces as $namespace) {
            $class = $namespace . $factoryName;
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Validate to-one association - stub for compatibility
     *
     * @param string $associationName
     * @param int $count
     * @return void
     * @throws \CakephpFixtureFactories\Error\AssociationBuilderException
     */
    public function validateToOneAssociation(string $associationName, int $count): void
    {
        if ($count > 1) {
            $association = $this->getAssociation($associationName);
            if (in_array($association->type(), ['manyToOne', 'oneToOne'])) {
                throw new AssociationBuilderException(
                    "Cannot create $count $associationName on a to-one association",
                );
            }
        }
    }
}
