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
use DummyGenerator\Container\DefinitionContainerInterface;
use DummyGenerator\DummyGenerator;
use DummyGenerator\Strategy\UniqueStrategy;
use InvalidArgumentException;
use OverflowException;
use ReflectionEnum;

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
     * @param string|null $locale The locale to use
     * @phpstan-ignore-next-line
     */
    public function __construct(?string $locale = null)
    {
        // DummyGenerator doesn't use locale in the same way as Faker
        // The $locale parameter is kept for interface compatibility
        $this->container = DefinitionContainerBuilder::all();

        // Configure with UniqueStrategy as suggested in PR comments
        $strategy = new UniqueStrategy(retries: 1000);

        $this->generator = new DummyGenerator($this->container, $strategy);
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        if ($seed !== null) {
            // Note: The current version of DummyGenerator (0.0.5) doesn't support
            // seeded randomization in the same way as Faker. The PR comment suggested
            // using XoshiroRandomizer, but that would require a newer version
            // or custom integration with the definition container.
            // For now, this is a no-op to maintain interface compatibility.
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

        // Handle enum method specially since DummyGenerator doesn't have it
        if ($name === 'enum' && count($arguments) === 1) {
            /** @var \BackedEnum $enumClass */
            $enumClass = $arguments[0];
            if (!is_string($enumClass) || !enum_exists($enumClass)) {
                throw new InvalidArgumentException("Invalid enum class: $enumClass");
            }

            $reflection = new ReflectionEnum($enumClass);
            if (!$reflection->isBacked()) {
                throw new InvalidArgumentException("Only backed enums are supported: $enumClass");
            }

            $cases = $enumClass::cases();
            if (empty($cases)) {
                throw new InvalidArgumentException("Enum has no cases: $enumClass");
            }

            // Use randomElement if available, otherwise fall back to array_rand
            if (method_exists($this->generator, 'randomElement')) {
                return $this->generator->randomElement(array_map(fn($case) => $case->value, $cases));
            } else {
                $randomCase = $cases[array_rand($cases)];

                return $randomCase->value;
            }
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
