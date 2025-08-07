<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         3.1.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpFixtureFactories\Generator;

use Faker\Factory;
use Faker\Generator;
use Throwable;

/**
 * Adapter for Faker library
 *
 * This adapter wraps the Faker library to implement the GeneratorInterface
 */
class FakerAdapter implements GeneratorInterface
{
    /**
     * @var \Faker\Generator
     */
    private Generator $generator;

    /**
     * Constructor
     *
     * @param string|null $locale The locale to use
     */
    public function __construct(?string $locale = null)
    {
        try {
            $this->generator = Factory::create($locale ?? Factory::DEFAULT_LOCALE);
        } catch (Throwable $e) {
            $this->generator = Factory::create(Factory::DEFAULT_LOCALE);
        }
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
        /** @var \Faker\UniqueGenerator $uniqueGenerator */
        $uniqueGenerator = $this->generator->unique();

        return new FakerUniqueAdapter($uniqueGenerator);
    }

    /**
     * @inheritDoc
     */
    public function optional(float $weight = 0.5): OptionalGeneratorInterface
    {
        return new FakerOptionalAdapter($this->generator->optional($weight));
    }
}
