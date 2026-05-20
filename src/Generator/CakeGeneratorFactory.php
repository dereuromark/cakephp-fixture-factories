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

use Cake\Core\Configure;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use DummyGenerator\DummyGenerator;
use Faker\Generator as FakerGenerator;

/**
 * Factory for creating generator instances
 *
 * This factory manages the creation of different generator implementations
 * and provides a central point for configuration and instantiation
 */
class CakeGeneratorFactory
{
    /**
     * Preferred default generator when both supported libraries are installed
     * and no explicit type is configured.
     *
     * @var string
     */
    public const DEFAULT_GENERATOR = 'faker';

    /**
     * Available generator adapters
     *
     * @var array<string, class-string>
     */
    private static array $adapters = [
        'faker' => FakerAdapter::class,
        'dummy' => DummyGeneratorAdapter::class,
    ];

    /**
     * Cached generator instances by locale
     *
     * @var array<string, \CakephpFixtureFactories\Generator\GeneratorInterface>
     */
    private static array $instances = [];

    /**
     * Cached auto-detection result. Reset by clearInstances().
     *
     * @var string|null
     */
    private static ?string $autoDetected = null;

    /**
     * Create a generator instance
     *
     * Resolution order for the generator type:
     * 1. The explicit `$type` argument, if provided.
     * 2. `Configure::read('FixtureFactories.generatorType')`, if set.
     * 3. Auto-detection: prefer Faker (preserves the prior default) and fall
     *    back to DummyGenerator when only that library is installed. If
     *    neither library is present, a {@see FixtureFactoryException} is
     *    thrown with installation guidance.
     *
     * @param string|null $locale The locale to use
     * @param string|null $type The generator type (faker, dummy)
     *
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    public static function create(?string $locale = null, ?string $type = null): GeneratorInterface
    {
        $type = $type
            ?? Configure::read('FixtureFactories.generatorType')
            ?? self::detectDefaultType();
        $locale = $locale ?? Configure::read('FixtureFactories.defaultLocale');

        $cacheKey = $type . '::' . ($locale ?? 'default');

        if (!isset(self::$instances[$cacheKey])) {
            self::$instances[$cacheKey] = self::createInstance($type, $locale);
        }

        return self::$instances[$cacheKey];
    }

    /**
     * Auto-detect which built-in generator library is installed.
     *
     * Faker wins the tie when both are present so existing setups behave
     * exactly as before. Result is memoized; reset via clearInstances().
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     *
     * @return string
     */
    private static function detectDefaultType(): string
    {
        if (self::$autoDetected !== null) {
            return self::$autoDetected;
        }

        if (class_exists(FakerGenerator::class)) {
            return self::$autoDetected = 'faker';
        }

        if (class_exists(DummyGenerator::class)) {
            return self::$autoDetected = 'dummy';
        }

        throw new FixtureFactoryException(
            'No random data generator found. Install either `fakerphp/faker` or '
            . '`johnykvsky/dummygenerator`, or register a custom adapter via '
            . 'CakeGeneratorFactory::registerAdapter().',
        );
    }

    /**
     * Create a new generator instance
     *
     * @param string $type Generator type
     * @param string|null $locale Locale
     *
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     *
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     */
    private static function createInstance(string $type, ?string $locale): GeneratorInterface
    {
        if (!isset(self::$adapters[$type])) {
            throw new FixtureFactoryException(
                sprintf('Unknown generator type `%s`. Available types: `%s`', $type, implode(', ', array_keys(self::$adapters))),
            );
        }

        $adapterClass = self::$adapters[$type];

        if (!class_exists($adapterClass)) {
            throw new FixtureFactoryException(
                sprintf('Generator adapter class `%s` not found', $adapterClass),
            );
        }

        /** @var \CakephpFixtureFactories\Generator\GeneratorInterface */
        return new $adapterClass($locale);
    }

    /**
     * Register a custom generator adapter
     *
     * @param string $name The name for the adapter
     * @param class-string $adapterClass The adapter class name
     *
     * @return void
     */
    public static function registerAdapter(string $name, string $adapterClass): void
    {
        self::$adapters[$name] = $adapterClass;
    }

    /**
     * Clear all cached instances
     *
     * @return void
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
        self::$autoDetected = null;
    }
}
