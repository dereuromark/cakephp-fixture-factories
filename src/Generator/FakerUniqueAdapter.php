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

use Faker\UniqueGenerator;
use OverflowException;
use ReflectionObject;

/**
 * Adapter for Faker's unique generator
 */
class FakerUniqueAdapter implements UniqueGeneratorInterface
{
    /**
     * @var \Faker\UniqueGenerator
     */
    private UniqueGenerator $generator;

    /**
     * Constructor
     *
     * @param \Faker\UniqueGenerator $generator The unique generator
     */
    public function __construct(UniqueGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        // Faker's UniqueGenerator doesn't have a reset method
        // We need to access the protected $uniques property via reflection
        $reflection = new ReflectionObject($this->generator);
        $uniquesProperty = $reflection->getProperty('uniques');
        $uniquesProperty->setAccessible(true);
        $uniquesProperty->setValue($this->generator, []);
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        // UniqueGenerator doesn't support seeding directly
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
     *
     * @throws \OverflowException
     */
    public function __call(string $name, array $arguments): mixed
    {
        // Handle enum methods that Faker's unique generator doesn't understand
        if ($name === 'enumValue' && count($arguments) === 1) {
            // Get the underlying generator and manually handle unique tracking
            $reflection = new ReflectionObject($this->generator);

            // Get unique values array
            $uniquesProperty = $reflection->getProperty('uniques');
            $uniquesProperty->setAccessible(true);
            $uniques = $uniquesProperty->getValue($this->generator);

            $maxRetries = 10000;
            $key = 'enumValue';

            for ($i = 0; $i < $maxRetries; $i++) {
                // Manually call enumValue on the FakerAdapter
                $adapter = new FakerAdapter();
                $value = $adapter->enumValue($arguments[0]);

                if (!isset($uniques[$key]) || !in_array($value, $uniques[$key], true)) {
                    $uniques[$key][] = $value;
                    $uniquesProperty->setValue($this->generator, $uniques);

                    return $value;
                }
            }

            throw new OverflowException("Unable to generate unique value for 'enumValue'");
        }

        if ($name === 'enumElement' && count($arguments) === 1) {
            // Similar handling for enumElement
            $reflection = new ReflectionObject($this->generator);

            // Get unique values array
            $uniquesProperty = $reflection->getProperty('uniques');
            $uniquesProperty->setAccessible(true);
            $uniques = $uniquesProperty->getValue($this->generator);

            $maxRetries = 10000;
            $key = 'enumElement';

            for ($i = 0; $i < $maxRetries; $i++) {
                // Manually call enumElement on the FakerAdapter
                $adapter = new FakerAdapter();
                $element = $adapter->enumElement($arguments[0]);

                if (!isset($uniques[$key]) || !in_array($element, $uniques[$key], true)) {
                    $uniques[$key][] = $element;
                    $uniquesProperty->setValue($this->generator, $uniques);

                    return $element;
                }
            }

            throw new OverflowException("Unable to generate unique value for 'enumElement'");
        }

        return $this->generator->$name(...$arguments);
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
        // This doesn't make sense for unique, but we need to support the interface
        // We need to get the underlying generator from UniqueGenerator
        $reflection = new ReflectionObject($this->generator);
        $generatorProperty = $reflection->getProperty('generator');
        $generatorProperty->setAccessible(true);
        /** @var \Faker\Generator $generator */
        $generator = $generatorProperty->getValue($this->generator);

        return new FakerOptionalAdapter($generator->optional($weight));
    }
}
