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

namespace CakephpFixtureFactories\Test\TestCase\ORM;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\ORM\SelectQueryMocker;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use TestApp\Model\Entity\Country;

#[AllowMockObjectsWithoutExpectations]
class SelectQueryMockerTest extends TestCase
{
    use TruncateDirtyTables;

    public function testSelectQueryMocker(): void
    {
        $names = ['Foo', 'Bar'];
        $countryFactory = CountryFactory::new([
            ['name' => $names[0]],
            ['name' => $names[1]],
        ]);
        SelectQueryMocker::mock($this, $countryFactory);

        $CountriesTable = TableRegistry::getTableLocator()->get('Countries');
        $countries = $CountriesTable->find();
        $this->assertSame(2, $countries->count());
        foreach ($countries as $i => $country) {
            $this->assertSame($names[$i], $country['name']);
        }

        $this->assertSame(0, CountryFactory::query()->count());
    }

    public function testSelectQueryMockerWithDataInDb(): void
    {
        $names = ['Foo', 'Bar'];
        $countryFactory = CountryFactory::new([
            ['name' => $names[0]],
            ['name' => $names[1]],
        ]);
        $nCountriesInDB = 4;
        CountryFactory::new($nCountriesInDB)->saveMany();
        SelectQueryMocker::mock($this, $countryFactory);

        $CountriesTable = TableRegistry::getTableLocator()->get('Countries');
        $countries = $CountriesTable->find();
        $this->assertSame(2, $countries->count());
        foreach ($countries as $i => $country) {
            $this->assertSame($names[$i], $country['name']);
        }

        $this->assertSame($nCountriesInDB, CountryFactory::query()->count());
    }

    public function testSelectQueryMockerWithAssociations(): void
    {
        $names = ['Foo', 'Bar'];
        $cityFactory = CityFactory::new([
            ['name' => $names[0]],
            ['name' => $names[1]],
        ])->forCountries();
        SelectQueryMocker::mock($this, $cityFactory);

        $CountriesTable = TableRegistry::getTableLocator()->get('Countries');
        $CitiesTable = TableRegistry::getTableLocator()->get('Cities');

        $cities = $CitiesTable->find();
        $this->assertSame(2, $cities->count());
        foreach ($cities as $i => $city) {
            $this->assertSame($names[$i], $city['name']);
            $this->assertInstanceOf(Country::class, $city['country']);
        }

        $countries = $CountriesTable->find();
        $this->assertSame(0, $countries->count());
        $this->assertSame([], $countries->toArray());

        $this->assertSame(0, CountryFactory::query()->count());
        $this->assertSame(0, CityFactory::query()->count());
    }
}
