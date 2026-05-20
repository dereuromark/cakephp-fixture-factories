<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Generator;

/**
 * Adapter for DummyGenerator's optional generator
 */
class DummyOptionalAdapter implements OptionalGeneratorInterface
{
    /**
     * @var \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    private GeneratorInterface $generator;

    /**
     * @var float
     */
    private float $weight;

    /**
     * Constructor
     *
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator The generator
     * @param float $weight The weight for optional values
     */
    public function __construct(GeneratorInterface $generator, float $weight = 0.5)
    {
        $this->generator = $generator;
        $this->weight = $weight;
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        $this->generator->seed($seed);
    }

    /**
     * @inheritDoc
     */
    public function __get(string $property): mixed
    {
        if ($this->shouldReturnValue()) {
            return $this->generator->__get($property);
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($this->shouldReturnValue()) {
            return $this->generator->__call($name, $arguments);
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function unique(): UniqueGeneratorInterface
    {
        // Delegate to the wrapped generator so the returned adapter has
        // unique-mode actually enabled. Wrapping in a fresh DummyUniqueAdapter
        // here would skip the clone+isUnique flip in DummyGeneratorAdapter::unique().
        return $this->generator->unique();
    }

    /**
     * @inheritDoc
     */
    public function optional(float $weight = 0.5): OptionalGeneratorInterface
    {
        // Already optional, return new instance with new weight
        return new self($this->generator, $weight);
    }

    /**
     * Determine if a value should be returned.
     *
     * Forwards the float weight (0.0..1.0) directly to DummyGenerator's
     * `boolean()`, which since v0.2.1 accepts the float form natively. This
     * keeps the dice roll on the wrapped generator's seeded randomizer (so
     * seeded tests stay deterministic) and preserves precision for very
     * small weights — `optional(0.001)` now actually fires ~0.1% of the
     * time instead of rounding to 0%.
     *
     * @return bool
     */
    private function shouldReturnValue(): bool
    {
        return (bool)$this->generator->__call('boolean', [$this->weight]);
    }
}
