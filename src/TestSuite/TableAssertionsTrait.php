<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\TestSuite;

use Cake\Datasource\EntityInterface;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use InvalidArgumentException;

/**
 * Expressive database-state assertions for tests that build data through
 * fixture factories.
 *
 * Composes over `Factory::query()` and `Factory::table()` — no static read
 * surface is added to `BaseFactory`. The trait is opt-in: `use TableAssertionsTrait;`
 * in any `Cake\TestSuite\TestCase` (or PHPUnit `TestCase`) subclass.
 *
 * ```php
 * use CakephpFixtureFactories\TestSuite\TableAssertionsTrait;
 *
 * class ArticlesControllerTest extends AppTestCase
 * {
 *     use TableAssertionsTrait;
 *
 *     public function testCreate(): void
 *     {
 *         $this->post('/articles', ['title' => 'Hello']);
 *
 *         $this->assertTableHas(ArticleFactory::class, ['title' => 'Hello']);
 *         $this->assertTableCount(ArticleFactory::class, 1);
 *         $this->assertTableMissing(ArticleFactory::class, ['status' => 'spam']);
 *     }
 * }
 * ```
 */
trait TableAssertionsTrait
{
    /**
     * Assert that the factory's table contains at least one row matching the
     * given criteria.
     *
     * @param class-string<\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $factoryClass
     * @param array<string, mixed> $criteria Where-clause-compatible criteria
     *     (e.g. `['name' => 'Kenya']`, `['title LIKE' => '%Cake%']`).
     * @param string|null $message Optional custom failure message; appended to the default explanation.
     */
    public function assertTableHas(string $factoryClass, array $criteria, ?string $message = null): void
    {
        self::guardFactoryClass($factoryClass);

        $count = $factoryClass::query()->where($criteria)->count();
        $this->assertGreaterThan(
            0,
            $count,
            self::composeMessage(
                sprintf(
                    'Expected %s to have at least one row matching %s, found none.',
                    self::shortName($factoryClass),
                    self::renderCriteria($criteria),
                ),
                $message,
            ),
        );
    }

    /**
     * Assert that the factory's table contains no rows matching the given criteria.
     *
     * @param class-string<\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $factoryClass
     * @param array<string, mixed> $criteria Where-clause-compatible criteria.
     * @param string|null $message Optional custom failure message.
     */
    public function assertTableMissing(string $factoryClass, array $criteria, ?string $message = null): void
    {
        self::guardFactoryClass($factoryClass);

        $count = $factoryClass::query()->where($criteria)->count();
        $this->assertSame(
            0,
            $count,
            self::composeMessage(
                sprintf(
                    'Expected %s to have no rows matching %s, found %d.',
                    self::shortName($factoryClass),
                    self::renderCriteria($criteria),
                    $count,
                ),
                $message,
            ),
        );
    }

    /**
     * Assert that the factory's table has an exact row count, optionally
     * narrowed by criteria.
     *
     * @param class-string<\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $factoryClass
     * @param int $expected Exact count expected.
     * @param array<string, mixed> $criteria Optional where-clause criteria.
     * @param string|null $message Optional custom failure message.
     */
    public function assertTableCount(
        string $factoryClass,
        int $expected,
        array $criteria = [],
        ?string $message = null,
    ): void {
        self::guardFactoryClass($factoryClass);

        $query = $factoryClass::query();
        if ($criteria !== []) {
            $query = $query->where($criteria);
        }
        $count = $query->count();
        $this->assertSame(
            $expected,
            $count,
            self::composeMessage(
                sprintf(
                    'Expected %d row%s in %s%s, found %d.',
                    $expected,
                    $expected === 1 ? '' : 's',
                    self::shortName($factoryClass),
                    $criteria === [] ? '' : ' matching ' . self::renderCriteria($criteria),
                    $count,
                ),
                $message,
            ),
        );
    }

    /**
     * Assert that the factory's table is empty.
     *
     * @param class-string<\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $factoryClass
     * @param string|null $message Optional custom failure message.
     */
    public function assertTableEmpty(string $factoryClass, ?string $message = null): void
    {
        self::guardFactoryClass($factoryClass);

        $count = $factoryClass::query()->count();
        $this->assertSame(
            0,
            $count,
            self::composeMessage(
                sprintf(
                    'Expected %s table to be empty, found %d row%s.',
                    self::shortName($factoryClass),
                    $count,
                    $count === 1 ? '' : 's',
                ),
                $message,
            ),
        );
    }

    /**
     * Assert that the given entity still exists in the database (by primary key).
     *
     * Uses the entity's `getSource()` plus the factory locator to query the
     * underlying table. The entity must have a primary key set; built-but-unsaved
     * entities will fail this assertion.
     *
     * @param \Cake\Datasource\EntityInterface $entity
     * @param string|null $message Optional custom failure message.
     */
    public function assertEntityExists(EntityInterface $entity, ?string $message = null): void
    {
        $exists = self::entityExistsInDatabase($entity);
        $this->assertTrue(
            $exists,
            self::composeMessage(
                sprintf(
                    'Expected entity `%s` (PK: %s) to exist in the database, but it does not.',
                    $entity::class,
                    self::renderPrimaryKey($entity),
                ),
                $message,
            ),
        );
    }

    /**
     * Assert that the given entity is no longer in the database.
     *
     * @param \Cake\Datasource\EntityInterface $entity
     * @param string|null $message Optional custom failure message.
     */
    public function assertEntityMissing(EntityInterface $entity, ?string $message = null): void
    {
        $exists = self::entityExistsInDatabase($entity);
        $this->assertFalse(
            $exists,
            self::composeMessage(
                sprintf(
                    'Expected entity `%s` (PK: %s) to be missing, but it still exists.',
                    $entity::class,
                    self::renderPrimaryKey($entity),
                ),
                $message,
            ),
        );
    }

    /**
     * @param class-string<\CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>> $factoryClass
     *
     * @throws \InvalidArgumentException
     */
    private static function guardFactoryClass(string $factoryClass): void
    {
        if (!is_subclass_of($factoryClass, BaseFactory::class)) {
            throw new InvalidArgumentException(sprintf(
                '`%s` must extend `%s`. The factory class is needed to resolve the table to query.',
                $factoryClass,
                BaseFactory::class,
            ));
        }
    }

    private static function entityExistsInDatabase(EntityInterface $entity): bool
    {
        $source = $entity->getSource();
        if ($source === '') {
            throw new InvalidArgumentException(
                'Cannot check existence of an entity with no source table. '
                . 'Build or save the entity through a factory first.',
            );
        }
        $table = FactoryTableRegistry::getTableLocator()->get($source);
        $primaryKey = (array)$table->getPrimaryKey();
        $conditions = [];
        foreach ($primaryKey as $field) {
            $value = $entity->get($field);
            if ($value === null) {
                return false;
            }
            $conditions[$table->aliasField($field)] = $value;
        }

        return $table->find()->where($conditions)->count() > 0;
    }

    private static function renderCriteria(array $criteria): string
    {
        $parts = [];
        foreach ($criteria as $key => $value) {
            $parts[] = sprintf('%s: %s', (string)$key, self::renderScalar($value));
        }

        return '{' . implode(', ', $parts) . '}';
    }

    private static function renderScalar(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }

        return get_debug_type($value);
    }

    private static function renderPrimaryKey(EntityInterface $entity): string
    {
        $source = $entity->getSource();
        if ($source === '') {
            return '(no source)';
        }
        $table = FactoryTableRegistry::getTableLocator()->get($source);
        $parts = [];
        foreach ((array)$table->getPrimaryKey() as $field) {
            $parts[] = sprintf('%s=%s', $field, self::renderScalar($entity->get($field)));
        }

        return implode(', ', $parts);
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private static function composeMessage(string $defaultMessage, ?string $userMessage): string
    {
        if ($userMessage === null || $userMessage === '') {
            return $defaultMessage;
        }

        return $defaultMessage . "\n" . $userMessage;
    }
}
