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
 * Adapter for DummyGenerator's unique generator
 */
class DummyUniqueAdapter implements UniqueGeneratorInterface
{
    /**
     * @var \CakephpFixtureFactories\Generator\DummyGeneratorAdapter
     */
    private DummyGeneratorAdapter $generator;

    /**
     * Constructor
     *
     * @param \CakephpFixtureFactories\Generator\DummyGeneratorAdapter $generator The generator
     */
    public function __construct(DummyGeneratorAdapter $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->generator->resetUnique();
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
        return $this->generator->__get($property);
    }

    /**
     * @inheritDoc
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->generator->__call($name, $arguments);
    }

    /**
     * @inheritDoc
     */
    public function unique(): UniqueGeneratorInterface
    {
        // Already unique, return self
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function optional(float $weight = 0.5): OptionalGeneratorInterface
    {
        return new DummyOptionalAdapter($this->generator, $weight);
    }
}
