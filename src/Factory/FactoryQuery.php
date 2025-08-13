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

use Cake\Database\ExpressionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Closure;

/**
 * Handles database queries for factories
 *
 * This class separates query concerns from factory creation,
 * providing a cleaner API for finding and counting entities.
 */
class FactoryQuery
{
    /**
     * @var \Cake\ORM\Table
     */
    private Table $table;

    /**
     * Constructor
     *
     * @param \Cake\ORM\Table $table The table instance
     * @param string $factoryClassName The factory class name (unused, for BC)
     */
    public function __construct(Table $table, string $factoryClassName)
    {
        $this->table = $table;
        // $factoryClassName kept for backward compatibility but not used
        unset($factoryClassName);
    }

    /**
     * Find an entity by primary key
     *
     * @param mixed $primaryKey The primary key value
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function find(mixed $primaryKey): ?EntityInterface
    {
        try {
            return $this->table->get($primaryKey);
        } catch (RecordNotFoundException $e) {
            return null;
        }
    }

    /**
     * Find an entity by conditions
     *
     * @param array<string, mixed> $conditions The conditions to match
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function findWhere(array $conditions): ?EntityInterface
    {
        return $this->table->find()
            ->where($conditions)
            ->first();
    }

    /**
     * Find all entities matching conditions
     *
     * @param array<string, mixed> $conditions The conditions to match
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function findAll(array $conditions = []): array
    {
        $query = $this->table->find();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->toArray();
    }

    /**
     * Get an entity by primary key or fail
     *
     * @param mixed $primaryKey The primary key value
     * @return \Cake\Datasource\EntityInterface
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function get(mixed $primaryKey): EntityInterface
    {
        return $this->table->get($primaryKey);
    }

    /**
     * Get first entity matching conditions or fail
     *
     * @param array<string, mixed> $conditions The conditions to match
     * @return \Cake\Datasource\EntityInterface
     * @throws \Cake\Datasource\Exception\RecordNotFoundException
     */
    public function firstOrFail(array $conditions = []): EntityInterface
    {
        $entity = $this->table->find()
            ->where($conditions)
            ->firstOrFail();

        return $entity;
    }

    /**
     * Count entities matching conditions
     *
     * @param array<string, mixed>|\Closure|\Cake\Database\ExpressionInterface|null $conditions
     * @return int
     */
    public function count(
        array|Closure|ExpressionInterface|null $conditions = null,
    ): int {
        $query = $this->table->find();

        if ($conditions !== null) {
            if ($conditions instanceof Closure) {
                $query = $conditions($query);
            } elseif (is_array($conditions)) {
                $query->where($conditions);
            } else {
                $query->where($conditions);
            }
        }

        return $query->count();
    }

    /**
     * Check if any entities exist matching conditions
     *
     * @param array<string, mixed> $conditions The conditions to match
     * @return bool
     */
    public function exists(array $conditions = []): bool
    {
        $query = $this->table->find();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->count() > 0;
    }

    /**
     * Get a random entity
     *
     * @param array<string, mixed> $conditions Optional conditions
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function random(array $conditions = []): ?EntityInterface
    {
        $query = $this->table->find();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        $count = $query->count();

        if ($count === 0) {
            return null;
        }

        $offset = rand(0, $count - 1);

        return $query
            ->limit(1)
            ->offset($offset)
            ->first();
    }

    /**
     * Get multiple random entities
     *
     * @param int $limit Number of entities to get
     * @param array<string, mixed> $conditions Optional conditions
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public function randomMany(int $limit, array $conditions = []): array
    {
        $query = $this->table->find();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query
            ->order('RAND()')
            ->limit($limit)
            ->toArray();
    }

    /**
     * Delete entities matching conditions
     *
     * @param array<string, mixed> $conditions The conditions to match
     * @return int Number of deleted records
     */
    public function delete(array $conditions): int
    {
        return $this->table->deleteAll($conditions);
    }

    /**
     * Delete all entities for this factory's table
     *
     * @return int Number of deleted records
     */
    public function deleteAll(): int
    {
        return $this->table->deleteAll([]);
    }

    /**
     * Get the underlying query builder
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function query(): SelectQuery
    {
        return $this->table->find();
    }

    /**
     * Get the table instance
     *
     * @return \Cake\ORM\Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }
}
