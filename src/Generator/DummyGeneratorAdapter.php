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

use BadMethodCallException;
use Cake\Utility\Text;
use DummyGenerator\Container\DefinitionContainerBuilder;
use DummyGenerator\DummyGenerator;
use DummyGenerator\Strategy\SimpleStrategy;
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
     * Constructor
     *
     * @param string|null $locale The locale to use
     * @phpstan-ignore-next-line
     */
    public function __construct(?string $locale = null)
    {
        // DummyGenerator doesn't use locale in the same way as Faker
        // The $locale parameter is kept for interface compatibility
        $container = DefinitionContainerBuilder::all();
        $strategy = new SimpleStrategy();
        $this->generator = new DummyGenerator($container, $strategy);
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        if ($seed !== null) {
            // DummyGenerator doesn't support seeding directly
            // We would need to implement a custom strategy with seeded randomizer
            // For now, this is a no-op to maintain interface compatibility
        }
    }

    /**
     * @inheritDoc
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
        // Handle shimmed methods that DummyGenerator doesn't support
        if ($name === 'uuid') {
            return $this->generateUuid();
        }

        // DummyGenerator uses __call for all its methods, so we try to call it directly
        try {
            if ($this->isUnique) {
                $maxRetries = 10000;
                $retries = 0;

                do {
                    $value = $this->generator->$name(...$arguments);
                    $key = $name . '::' . serialize($value);

                    if (!isset($this->uniqueValues[$key])) {
                        $this->uniqueValues[$key] = true;

                        return $value;
                    }

                    $retries++;
                } while ($retries < $maxRetries);

                throw new OverflowException("Unable to generate unique value for '$name' after $maxRetries attempts");
            }

            return $this->generator->$name(...$arguments);
        } catch (BadMethodCallException $e) {
            throw new BadMethodCallException("Method `$name` not found");
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

    /**
     * Generate a UUID v4 string
     *
     * This is a shim method since DummyGenerator doesn't support uuid() natively.
     * Generates a proper UUID v4 compatible string.
     *
     * @return string UUID v4 string
     */
    private function generateUuid(): string
    {
        if ($this->isUnique) {
            $maxRetries = 10000;
            $retries = 0;

            do {
                $uuid = $this->createUuidV4();
                $key = 'uuid::' . $uuid;

                if (!isset($this->uniqueValues[$key])) {
                    $this->uniqueValues[$key] = true;

                    return $uuid;
                }

                $retries++;
            } while ($retries < $maxRetries);

            throw new OverflowException("Unable to generate unique UUID after $maxRetries attempts");
        }

        return $this->createUuidV4();
    }

    /**
     * Create a UUID v4 string
     *
     * @return string UUID v4 string
     */
    private function createUuidV4(): string
    {
        return Text::uuid();
    }
}
