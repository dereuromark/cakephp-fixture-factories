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

use BadMethodCallException;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use DummyGenerator\Container\DefinitionContainerBuilder;
use DummyGenerator\Container\DefinitionContainerInterface;
use DummyGenerator\Core\Randomizer\XoshiroRandomizer;
use DummyGenerator\Definitions\Randomizer\RandomizerInterface;
use DummyGenerator\DummyGenerator;
use DummyGenerator\Strategy\UniqueStrategy;
use InvalidArgumentException;
use OverflowException;

/**
 * Adapter for DummyGenerator library
 *
 * This adapter wraps the DummyGenerator library to implement the GeneratorInterface
 */
class DummyGeneratorAdapter implements GeneratorInterface
{
    /**
     * @var \DummyGenerator\DummyGenerator
     */
    private DummyGenerator $generator;

    /**
     * @var array<string, bool> Tracked unique values
     */
    private array $uniqueValues = [];

    /**
     * @var bool Whether we're in unique mode
     */
    private bool $isUnique = false;

    /**
     * @var \DummyGenerator\Container\DefinitionContainerInterface
     */
    private DefinitionContainerInterface $container;

    /**
     * Constructor
     *
     * @phpstan-ignore-next-line
     *
     * @param string|null $locale The locale to use
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException if DummyGenerator library is not installed
     */
    public function __construct(?string $locale = null)
    {
        if (!class_exists(DummyGenerator::class)) {
            throw new FixtureFactoryException(
                'DummyGenerator library is not installed. Please install it using: `composer require johnykvsky/dummygenerator`',
            );
        }

        // DummyGenerator doesn't use locale in the same way as Faker
        // The $locale parameter is kept for interface compatibility
        $this->container = DefinitionContainerBuilder::all();

        $strategy = new UniqueStrategy(retries: 1000);

        $this->generator = new DummyGenerator($this->container, $strategy);
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        if ($seed !== null) {
            // Use XoshiroRandomizer with seed for deterministic generation
            $this->container->add(
                RandomizerInterface::class,
                new XoshiroRandomizer($seed),
            );

            // Recreate the generator with the updated container
            $strategy = new UniqueStrategy(retries: 1000);
            $this->generator = new DummyGenerator($this->container, $strategy);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \BadMethodCallException
     */
    public function __get(string $property): mixed
    {
        // DummyGenerator doesn't support property access, convert to method call
        if (method_exists($this->generator, $property)) {
            return $this->__call($property, []);
        }

        throw new BadMethodCallException("Property or method `$property` not found");
    }

    /**
     * @inheritDoc
     */
    public function __call(string $name, array $arguments): mixed
    {
        // Map uuid() to uuid4() for compatibility
        if ($name === 'uuid') {
            return $this->handleUniqueCall('uuid4', []);
        }

        // DummyGenerator uses __call for all its methods, so we try to call it directly
        return $this->handleUniqueCall($name, $arguments);
    }

    /**
     * Handle method calls with unique value tracking
     *
     * @param string $method The method name to call
     * @param array $arguments The arguments to pass
     *
     * @throws \OverflowException If unable to generate unique value
     * @throws \BadMethodCallException If method not found
     *
     * @return mixed The result of the method call
     */
    private function handleUniqueCall(string $method, array $arguments): mixed
    {
        try {
            if ($this->isUnique) {
                $maxRetries = 10000;
                $retries = 0;

                do {
                    $value = $this->generator->$method(...$arguments);
                    $key = $method . '::' . serialize($value);

                    if (!isset($this->uniqueValues[$key])) {
                        $this->uniqueValues[$key] = true;

                        return $value;
                    }

                    $retries++;
                } while ($retries < $maxRetries);

                throw new OverflowException("Unable to generate unique value for '$method' after $maxRetries attempts");
            }

            return $this->generator->$method(...$arguments);
        } catch (BadMethodCallException | InvalidArgumentException $e) {
            throw new BadMethodCallException("Method `$method` not found");
        }
    }

    /**
     * @inheritDoc
     */
    public function unique(): UniqueGeneratorInterface
    {
        $adapter = clone $this;
        $adapter->isUnique = true;

        return new DummyUniqueAdapter($adapter);
    }

    /**
     * @inheritDoc
     */
    public function optional(float $weight = 0.5): OptionalGeneratorInterface
    {
        return new DummyOptionalAdapter($this, $weight);
    }

    /**
     * Reset unique values tracking
     *
     * @return void
     */
    public function resetUnique(): void
    {
        $this->uniqueValues = [];
    }
}
