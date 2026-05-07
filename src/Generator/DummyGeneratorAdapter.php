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
use Cake\Core\Configure;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use DummyGenerator\Core\Randomizer\XoshiroRandomizer;
use DummyGenerator\Definitions\Randomizer\RandomizerInterface;
use DummyGenerator\DummyGenerator;
use InvalidArgumentException;
use OverflowException;

/**
 * Adapter for DummyGenerator library
 *
 * This adapter wraps the DummyGenerator library to implement the GeneratorInterface
 *
 * Compatibility notes:
 * - uuid() is mapped to uuid4()
 * - enumElement() is mapped to enumCase() for Faker compatibility (deprecated, kept for backward compatibility)
 *
 * For enum support, use:
 * - enumCase(EnumClass::class) - returns a random enum case
 * - enumValue(BackedEnumClass::class) - returns a random backed enum value
 *
 * @method \UnitEnum enumCase(string $enumClass) Get a random enum case
 * @method string|int enumValue(string $enumClass) Get a random backed enum value
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
     * Constructor.
     *
     * Locale is accepted for interface compatibility; DummyGenerator does
     * not use locale in the same way Faker does.
     *
     * @param string|null $locale The locale to use (unused, kept for interface compatibility)
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException if DummyGenerator library is not installed.
     */
    public function __construct(?string $locale = null) // @phpstan-ignore constructor.unusedParameter
    {
        if (!class_exists(DummyGenerator::class)) {
            throw new FixtureFactoryException(
                'DummyGenerator library is not installed. Please install it using: `composer require --dev johnykvsky/dummygenerator`',
            );
        }

        $this->generator = DummyGenerator::create();
    }

    /**
     * @inheritDoc
     */
    public function seed(?int $seed = null): void
    {
        if ($seed === null) {
            return;
        }

        $this->generator = $this->generator->withDefinition(
            RandomizerInterface::class,
            new XoshiroRandomizer($seed),
        );
    }

    /**
     * @inheritDoc
     *
     * DummyGenerator dispatches via `__call`, so most "Faker-style" properties
     * (e.g. `$gen->name`, `$gen->email`) resolve via `__call` rather than as
     * real methods. We unconditionally delegate to `__call`; if the underlying
     * provider doesn't expose the method, `handleUniqueCall` will throw.
     */
    public function __get(string $property): mixed
    {
        return $this->__call($property, []);
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

        // Shim for randomAscii() - generate random ASCII character
        if ($name === 'randomAscii') {
            // ASCII printable characters range from 33 to 126
            $asciiCode = $this->handleUniqueCall('numberBetween', [33, 126]);

            return chr($asciiCode);
        }

        // Map realText() to text() for compatibility with Faker
        // DummyGenerator only has text(), not realText()
        if ($name === 'realText') {
            // Use same parameter as text() (maxCharacters)
            $maxNbChars = $arguments[0] ?? 200;

            return $this->handleUniqueCall('text', [$maxNbChars]);
        }

        // Map enumElement() to enumCase() for compatibility with Faker
        // @deprecated Use enumCase() instead - enumElement() is Faker-specific
        if ($name === 'enumElement') {
            // Soft deprecation - log warning if in debug mode and not in test environment
            if (
                class_exists('\Cake\Core\Configure') &&
                Configure::read('debug') &&
                !defined('PHPUNIT_COMPOSER_INSTALL') // Skip deprecation in PHPUnit tests
            ) {
                trigger_error(
                    'DummyGeneratorAdapter::enumElement() is deprecated. Use enumCase() instead for DummyGenerator compatibility.',
                    E_USER_DEPRECATED,
                );
            }

            return $this->handleUniqueCall('enumCase', $arguments);
        }

        // DummyGenerator uses __call for all its methods, so we try to call it directly
        return $this->handleUniqueCall($name, $arguments);
    }

    /**
     * Handle method calls with unique value tracking
     *
     * @param string $method The method name to call
     * @param array<mixed> $arguments The arguments to pass
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

                throw new OverflowException("Unable to generate unique value for `$method` after $maxRetries attempts");
            }

            return $this->generator->$method(...$arguments);
        } catch (BadMethodCallException | InvalidArgumentException $e) {
            throw new BadMethodCallException("Method `$method` not found on DummyGenerator");
        }
    }

    /**
     * @inheritDoc
     */
    public function unique(): UniqueGeneratorInterface
    {
        // Clone to share the same underlying generator but have separate unique tracking.
        $adapter = clone $this;
        $adapter->isUnique = true;
        $adapter->uniqueValues = [];

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
