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

use Faker\ChanceGenerator;
use Faker\Generator;
use ReflectionObject;

/**
 * Adapter for Faker's optional generator
 */
class FakerOptionalAdapter implements OptionalGeneratorInterface
{
    /**
     * @var \Faker\ChanceGenerator|\Faker\Generator
     */
    private ChanceGenerator|Generator $generator;

    /**
     * Constructor
     *
     * @param \Faker\ChanceGenerator|\Faker\Generator $generator The optional generator
     */
    public function __construct(ChanceGenerator|Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        // OptionalGenerator doesn't support seeding directly
    }

    /**
     * @inheritDoc
     */
    public function __get(string $property): mixed
    {
        return $this->generator->$property;
    }

    /**
     * @inheritDoc
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->generator->$name(...$arguments);
    }

    /**
     * @inheritDoc
     */
    public function unique(): UniqueGeneratorInterface
    {
        if ($this->generator instanceof ChanceGenerator) {
            // ChanceGenerator doesn't have unique method, get the underlying generator
            $reflection = new ReflectionObject($this->generator);
            $generatorProperty = $reflection->getProperty('generator');
            $generator = $generatorProperty->getValue($this->generator);
            /** @var \Faker\UniqueGenerator $uniqueGenerator */
            $uniqueGenerator = $generator->unique();

            return new FakerUniqueAdapter($uniqueGenerator);
        }

        /** @var \Faker\UniqueGenerator $uniqueGenerator */
        $uniqueGenerator = $this->generator->unique();

        return new FakerUniqueAdapter($uniqueGenerator);
    }

    /**
     * @inheritDoc
     */
    public function optional(float $weight = 0.5): OptionalGeneratorInterface
    {
        // Already optional, return self
        return $this;
    }
}
