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

use Cake\Core\Configure;
use CakephpFixtureFactories\Error\FixtureFactoryException;

/**
 * Factory for creating generator instances
 *
 * This factory manages the creation of different generator implementations
 * and provides a central point for configuration and instantiation
 */
class CakeGeneratorFactory
{
    /**
     * Default generator implementation
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
     * Create a generator instance
     *
     * @param string|null $locale The locale to use
     * @param string|null $type The generator type (faker, dummy)
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
     */
    public static function create(?string $locale = null, ?string $type = null): GeneratorInterface
    {
        $type = $type ?? Configure::read('FixtureFactories.generatorType', self::DEFAULT_GENERATOR);
        $locale = $locale ?? Configure::read('FixtureFactories.defaultLocale');

        $cacheKey = $type . '::' . ($locale ?? 'default');

        if (!isset(self::$instances[$cacheKey])) {
            self::$instances[$cacheKey] = self::createInstance($type, $locale);
        }

        return self::$instances[$cacheKey];
    }

    /**
     * Create a new generator instance
     *
     * @param string $type Generator type
     * @param string|null $locale Locale
     * @return \CakephpFixtureFactories\Generator\GeneratorInterface
     * @throws \CakephpFixtureFactories\Error\FixtureFactoryException
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
    }
}
