<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\ORM;

use Cake\ORM\Locator\LocatorInterface;

/**
 * Singleton holder for the {@see FactoryTableLocator} used by fixture factories.
 *
 * Tables fetched through this locator are stripped-down versions of their
 * application Table counterparts:
 * - Behaviors
 * - Events
 * - Validation
 *
 * The goal is twofold:
 * - Factory tables stay side-effect-free — they only insert fixture data and
 *   should not behave like application tables.
 * - The fixture insert path is faster.
 *
 * Implemented as a standalone holder rather than a subclass of
 * {@see \Cake\ORM\TableRegistry}: the only contract is the static
 * `getTableLocator()` accessor, and inheriting Cake's other static methods
 * would silently delegate to Cake's global locator and confuse callers.
 */
class FactoryTableRegistry
{
    /**
     * Default LocatorInterface implementation class.
     *
     * @var class-string<\Cake\ORM\Locator\LocatorInterface>
     */
    protected static string $_defaultLocatorClass = FactoryTableLocator::class;

    /**
     * @var \Cake\ORM\Locator\LocatorInterface|null
     */
    protected static ?LocatorInterface $_locator = null;

    /**
     * Returns the singleton locator used by fixture factories.
     *
     * @return \Cake\ORM\Locator\LocatorInterface
     */
    public static function getTableLocator(): LocatorInterface
    {
        if (self::$_locator !== null) {
            return self::$_locator;
        }

        /** @var \Cake\ORM\Locator\LocatorInterface $locator */
        $locator = new static::$_defaultLocatorClass();
        self::$_locator = $locator;

        return $locator;
    }

    /**
     * Override the singleton locator. Mirrors the static API previously
     * inherited from {@see \Cake\ORM\TableRegistry} — bootstraps and tests
     * that swap the fixture-factory locator continue to work after the
     * inheritance was dropped.
     *
     * @param \Cake\ORM\Locator\LocatorInterface $tableLocator Locator to install.
     *
     * @return void
     */
    public static function setTableLocator(LocatorInterface $tableLocator): void
    {
        self::$_locator = $tableLocator;
    }
}
