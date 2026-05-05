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

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Test\Factory\SchemaAwareCountryFactory;
use CakephpFixtureFactories\Test\Factory\StrictCityFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

class BaseFactoryOptionsTest extends TestCase
{
    use TruncateDirtyTables;

    public function testSetSaveOptionsIsImmutableAndSupportsSubclassExtensions(): void
    {
        $factory = StrictCityFactory::new(['name' => 'Default rules off']);
        $strictFactory = $factory->enableCheckRules();

        $savedCity = $factory->save();
        $this->assertSame('Default rules off', $savedCity->name);

        $this->expectException(PersistenceException::class);
        $strictFactory->save();
    }

    public function testSetMarshallerOptionsIsImmutable(): void
    {
        $factory = StrictCityFactory::new();
        $customFactory = $factory->setMarshallerOptions(['validate' => 'custom']);

        $this->assertFalse($factory->getMarshallerOptions()['validate']);
        $this->assertSame('custom', $customFactory->getMarshallerOptions()['validate']);
    }

    public function testDefinitionCanUseGetTableForSchemaAccess(): void
    {
        $country = SchemaAwareCountryFactory::new()->build();

        $this->assertSame('Countries', $country->name);
    }
}
