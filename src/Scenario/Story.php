<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Scenario;

use Cake\Datasource\EntityInterface;
use CakephpFixtureFactories\Error\FixtureScenarioException;

/**
 * Scenario abstract that adds **named entity pools** on top of
 * `FixtureScenarioInterface`.
 *
 * Use a Story when you want to seed test data once, store named handles to
 * subsets of it, and then sample from those handles in the body of the
 * test — without rebuilding entities or threading explicit variables.
 *
 * ```php
 * class BlogStory extends Story
 * {
 *     protected function build(): void
 *     {
 *         $this->addToPool('authors', UserFactory::new()->count(10)->saveMany());
 *         $this->addToPool('categories', CategoryFactory::new()->count(3)->saveMany());
 *
 *         ArticleFactory::new()->count(50)->saveMany();
 *     }
 * }
 *
 * // In the test:
 * $story = $this->loadFixtureScenario(BlogStory::class);
 * $anyAuthor = $story->getRandom('authors');
 * $someTags = $story->getRandomSet('categories', 2);
 * ```
 *
 * Backwards-compatible: `Story` `implements FixtureScenarioInterface`, so
 * `ScenarioAwareTrait::loadFixtureScenario(...)` resolves and calls `load()`
 * the same way it does for plain scenarios. Existing scenarios that
 * implement the interface directly keep working unchanged.
 */
abstract class Story implements FixtureScenarioInterface
{
    /**
     * @var array<string, array<int, \Cake\Datasource\EntityInterface>>
     */
    private array $pools = [];

    /**
     * @inheritDoc
     *
     * Dispatches to a `build(...)` method on the concrete subclass and
     * returns `$this` so the caller keeps a typed handle for `getRandom()`
     * / `getPool()` lookups.
     *
     * The `build()` signature isn't declared abstract — PHP's strict-types
     * LSP would prevent subclasses from accepting specific parameters
     * (`build(int $n)`, `build(string $kind)`, etc.). Instead, this method
     * dispatches via callable so subclasses can declare whatever signature
     * fits their needs, and `loadFixtureScenario($story, ...$args)` forwards
     * the arguments unchanged.
     *
     * @throws \CakephpFixtureFactories\Error\FixtureScenarioException When the
     *     subclass does not define a `build()` method.
     *
     * @return static
     */
    public function load(mixed ...$args): static
    {
        if (!method_exists($this, 'build')) {
            throw new FixtureScenarioException(sprintf(
                '`%s` extends `Story` but does not define a `build(...)` method. '
                . 'Declare `protected function build(): void` (or with whatever '
                . 'parameters your scenario takes) to seed your data.',
                static::class,
            ));
        }
        /** @var callable $build */
        $build = [$this, 'build'];
        $build(...$args);

        return $this;
    }

    /**
     * Register one or more entities under a named pool. Repeated calls with
     * the same pool name append.
     *
     * @param string $pool Pool identifier (any non-empty string).
     * @param \Cake\Datasource\EntityInterface|array<int, \Cake\Datasource\EntityInterface> $entities
     */
    protected function addToPool(string $pool, EntityInterface|array $entities): static
    {
        if ($entities instanceof EntityInterface) {
            $entities = [$entities];
        }
        if (!isset($this->pools[$pool])) {
            $this->pools[$pool] = [];
        }
        foreach ($entities as $entity) {
            $this->pools[$pool][] = $entity;
        }

        return $this;
    }

    /**
     * Return every entity registered under the given pool name.
     *
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function getPool(string $pool): array
    {
        $this->guardPoolExists($pool);

        return $this->pools[$pool];
    }

    /**
     * Return a uniformly random entity from the named pool.
     *
     * @throws \CakephpFixtureFactories\Error\FixtureScenarioException When the pool does not exist or is empty.
     */
    public function getRandom(string $pool): EntityInterface
    {
        $this->guardPoolExists($pool);
        $entities = $this->pools[$pool];
        if ($entities === []) {
            throw new FixtureScenarioException(sprintf(
                'Cannot draw from empty pool `%s`.',
                $pool,
            ));
        }

        return $entities[array_rand($entities)];
    }

    /**
     * Return `$count` distinct entities drawn from the named pool.
     *
     * @throws \CakephpFixtureFactories\Error\FixtureScenarioException When the pool does not
     *     exist or holds fewer entities than requested.
     *
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function getRandomSet(string $pool, int $count): array
    {
        if ($count < 0) {
            throw new FixtureScenarioException(sprintf(
                '$count must be ≥ 0, got %d.',
                $count,
            ));
        }
        // Validate the pool name even on zero-count requests so that typoed
        // pool names surface immediately instead of returning a silent [].
        $this->guardPoolExists($pool);
        if ($count === 0) {
            return [];
        }
        $entities = $this->pools[$pool];
        $available = count($entities);
        if ($count > $available) {
            throw new FixtureScenarioException(sprintf(
                'Cannot draw %d entities from pool `%s`: pool holds %d.',
                $count,
                $pool,
                $available,
            ));
        }
        $keys = array_rand($entities, $count);
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return array_values(array_map(static fn ($k) => $entities[$k], $keys));
    }

    private function guardPoolExists(string $pool): void
    {
        if (!isset($this->pools[$pool])) {
            throw new FixtureScenarioException(sprintf(
                'Pool `%s` does not exist on `%s`. Available pools: %s.',
                $pool,
                static::class,
                $this->pools === [] ? '(none)' : '`' . implode('`, `', array_keys($this->pools)) . '`',
            ));
        }
    }
}
