<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 3.1.0
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
     * Routes through the wrapped generator's randomizer so seeded tests stay
     * deterministic. Falling back to mt_rand() bypasses any seed configured
     * via {@see GeneratorInterface::seed()}.
     *
     * @return bool
     */
    private function shouldReturnValue(): bool
    {
        $threshold = (int)round($this->weight * 100);
        $roll = $this->generator->__call('numberBetween', [1, 100]);

        return is_int($roll) && $roll <= $threshold;
    }
}
