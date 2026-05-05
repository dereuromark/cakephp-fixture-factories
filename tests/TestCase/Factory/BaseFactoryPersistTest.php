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

    public function testSaveReturnsTheEntity(): void
    {
        $name = 'Foo';

        $country = CountryFactory::new(['name' => $name])->save();

        $this->assertInstanceOf(Country::class, $country);
        $this->assertSame($name, $country->get('name'));
        $this->assertNotNull($country->get('id'));
        $this->assertSame(1, CountryFactory::query()->count());
    }

    public function testSaveRejectsMultiEntityFactory(): void
    {
        $factory = CountryFactory::new([
            ['name' => 'Foo'],
            ['name' => 'Bar'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('save() expected to persist exactly 1 entity, but 2 were produced');

        $factory->save();
    }

    public function testSaveManyReturnsArrayForMultiEntityFactory(): void
    {
        $countries = CountryFactory::new([
            ['name' => 'Foo'],
            ['name' => 'Bar'],
        ])->saveMany();

        $this->assertIsArray($countries);
        $this->assertCount(2, $countries);
        $this->assertContainsOnlyInstancesOf(Country::class, $countries);
        $this->assertSame('Foo', $countries[0]->get('name'));
        $this->assertSame('Bar', $countries[1]->get('name'));
        $this->assertSame(2, CountryFactory::query()->count());
    }

    public function testSaveManyReturnsArrayForSingleEntityFactory(): void
    {
        $countries = CountryFactory::new(['name' => 'Foo'])->saveMany();

        $this->assertIsArray($countries);
        $this->assertCount(1, $countries);
        $this->assertInstanceOf(Country::class, $countries[0]);
        $this->assertSame('Foo', $countries[0]->get('name'));
        $this->assertSame(1, CountryFactory::query()->count());
    }

    public function testSaveAndSaveManyHaveExplicitReturnShapes(): void
    {
        $singular = CountryFactory::new(['name' => 'Foo'])->save();
        $this->assertInstanceOf(Country::class, $singular);

        $multiple = CountryFactory::new([['name' => 'Bar'], ['name' => 'Baz']])->saveMany();
        $this->assertIsArray($multiple);
        $this->assertNotInstanceOf(Country::class, $multiple);

        $this->assertSame(3, CountryFactory::query()->count());
    }
}
