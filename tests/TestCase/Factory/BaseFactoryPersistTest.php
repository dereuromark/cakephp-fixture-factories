<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.6
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use RuntimeException;
use TestApp\Model\Entity\Country;

class BaseFactoryPersistTest extends TestCase
{
    use TruncateDirtyTables;

    public static function setUpBeforeClass(): void
    {
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
    }

    public static function tearDownAfterClass(): void
    {
        Configure::delete('FixtureFactories.testFixtureNamespace');
    }

    public function testPersistOneReturnsTheEntity(): void
    {
        $name = 'Foo';

        $country = CountryFactory::make(['name' => $name])->persistOne();

        $this->assertInstanceOf(Country::class, $country);
        $this->assertSame($name, $country->get('name'));
        $this->assertNotNull($country->get('id'));
        $this->assertSame(1, CountryFactory::count());
    }

    public function testPersistOneRejectsMultiEntityFactory(): void
    {
        $factory = CountryFactory::make([
            ['name' => 'Foo'],
            ['name' => 'Bar'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('persistOne() expected to persist exactly 1 entity, but 2 were produced');

        $factory->persistOne();
    }

    public function testPersistManyReturnsArrayForMultiEntityFactory(): void
    {
        $countries = CountryFactory::make([
            ['name' => 'Foo'],
            ['name' => 'Bar'],
        ])->persistMany();

        $this->assertIsArray($countries);
        $this->assertCount(2, $countries);
        $this->assertContainsOnlyInstancesOf(Country::class, $countries);
        $this->assertSame('Foo', $countries[0]->get('name'));
        $this->assertSame('Bar', $countries[1]->get('name'));
        $this->assertSame(2, CountryFactory::count());
    }

    public function testPersistManyReturnsArrayForSingleEntityFactory(): void
    {
        $countries = CountryFactory::make(['name' => 'Foo'])->persistMany();

        $this->assertIsArray($countries);
        $this->assertCount(1, $countries);
        $this->assertInstanceOf(Country::class, $countries[0]);
        $this->assertSame('Foo', $countries[0]->get('name'));
        $this->assertSame(1, CountryFactory::count());
    }

    /**
     * The deprecated persist() must still return a single entity for the
     * singular call shape and an iterable for the multi-entity call shape.
     */
    public function testDeprecatedPersistKeepsLegacyReturnShape(): void
    {
        $singular = CountryFactory::make(['name' => 'Foo'])->persist();
        $this->assertInstanceOf(Country::class, $singular);

        $multiple = CountryFactory::make([['name' => 'Bar'], ['name' => 'Baz']])->persist();
        $this->assertIsIterable($multiple);
        $this->assertNotInstanceOf(Country::class, $multiple);

        $this->assertSame(3, CountryFactory::count());
    }
}
