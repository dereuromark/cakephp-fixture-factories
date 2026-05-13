<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Factory;

use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Context passed to a `sequence()` callable.
 *
 * Bundles everything the callable might need — iteration position, the
 * owning factory, the configured generator — behind a single argument
 * so the closure signature stays compact regardless of which bits are
 * actually used:
 *
 * ```php
 * ArticleFactory::new()
 *     ->count(5)
 *     ->sequence(fn (Sequence $s) => [
 *         'rank' => $s->index,
 *         'position' => $s->position,
 *         'total' => $s->total,
 *         'is_first' => $s->isFirst(),
 *         'is_last' => $s->isLast(),
 *         'slug' => $s->generator->slug(),
 *         'parent' => $s->factory->getTable()->getAlias(),
 *     ])
 *     ->saveMany();
 * ```
 *
 * Construction is internal to the data compiler; callables never need to
 * instantiate `Sequence` themselves.
 *
 * @template TEntity of \Cake\Datasource\EntityInterface
 */
final readonly class Sequence
{
    /**
     * 1-based position of the current row in the batch (`index + 1`).
     */
    public int $position;

    /**
     * Whether this is the very first row of the batch (`index === 0`).
     */
    public bool $isFirst;

    /**
     * Whether this is the very last row of the batch (`index === total - 1`).
     */
    public bool $isLast;

    /**
     * @param int $index 0-based iteration index across the factory's `count(N)` builds.
     * @param int $total Total number of entities the factory will build (`BaseFactory::getTimes()`).
     * @param \CakephpFixtureFactories\Factory\BaseFactory<TEntity> $factory Owning factory.
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator Configured fixture generator (Faker / DummyGenerator / custom).
     */
    public function __construct(
        public int $index,
        public int $total,
        public BaseFactory $factory,
        public GeneratorInterface $generator,
    ) {
        $this->position = $index + 1;
        $this->isFirst = $index === 0;
        $this->isLast = $index === $total - 1;
    }
}
