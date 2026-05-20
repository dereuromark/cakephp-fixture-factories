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

use Cake\Core\Configure;
use Cake\Database\Driver\Postgres;
use Cake\ORM\Query\SelectQuery;
use Cake\TestSuite\TestCase;
use Cake\Utility\Hash;
use CakephpFixtureFactories\Error\AssociationBuilderException;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\CustomerFactory;
use CakephpFixtureFactories\Test\Factory\SubDirectory\SubCityFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use TestApp\Model\Entity\Address;
use TestApp\Model\Entity\City;
use TestApp\Model\Entity\Country;
use TestApp\Model\Entity\PremiumAuthor;
use TestApp\Model\Table\PremiumAuthorsTable;
use TestPlugin\Model\Entity\Bill;
use TestPlugin\Model\Entity\Customer;

class BaseFactoryAssociationsTest extends TestCase
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

    public function testWithMultipleAssociations(): void
    {
        $n = 5;
        $street = 'FooStreet';
        $article = ArticleFactory::new()
            ->with("Authors[$n].Address", compact('street'))
            ->save();

        $this->assertSame($n, count($article->authors));
        foreach ($article->authors as $author) {
            $this->assertSame($street, $author->address->street);
        }
        $this->assertSame($n, AuthorFactory::query()->count());
    }

    public function testWithMultipleHasOneExeption(): void
    {
        $this->expectException(AssociationBuilderException::class);
        ArticleFactory::new()
            ->with('Authors.Address[2]')
            ->build();
    }

    public function testWithMultipleAssociationsDeep(): void
    {
        $nAuthors = 3;
        $mArticles = 5;
        $article = ArticleFactory::new()
            ->with("Authors[$nAuthors].Articles[$mArticles].Bills", BillFactory::new()->without('Article'))
            ->save();

        $authors = $article->authors;
        $this->assertSame($nAuthors, count($authors));
        foreach ($article->authors as $author) {
            $this->assertSame($mArticles, count($author->articles));
            foreach ($author->articles as $article) {
                $this->assertSame(1, count($article->bills));
            }
        }

        $expectedAuthors = $nAuthors * ($mArticles * 2 + 1);
        $this->assertSame($expectedAuthors, AuthorFactory::query()->count());

        $expectedArticles = 1 + ($nAuthors * $mArticles);
        $this->assertSame($expectedArticles, ArticleFactory::query()->count());
    }

    public function testSaveMultipleInArray(): void
    {
        $name1 = 'Foo';
        $name2 = 'Bar';
        $countries = CountryFactory::new([
            ['name' => $name1],
            ['name' => $name2],
        ])->saveMany();

        $this->assertSame(2, CountryFactory::query()->count());
        $this->assertSame($name1, $countries[0]->name);
        $this->assertSame($name2, $countries[1]->name);
        $this->assertSame($name1, CountryFactory::table()->get($countries[0]->id)->name);
        $this->assertSame($name2, CountryFactory::table()->get($countries[1]->id)->name);
    }

    public function testSaveMultipleInArrayWithTimes(): void
    {
        $times = 2;
        $name1 = 'Foo';
        $name2 = 'Bar';
        $countries = CountryFactory::new([
            ['name' => $name1],
            ['name' => $name2],
        ], $times)->saveMany();

        $this->assertSame($times * 2, CountryFactory::query()->count());

        $this->assertSame($name1, $countries[0]->name);
        $this->assertSame($name2, $countries[1]->name);
        $this->assertSame($name1, $countries[2]->name);
        $this->assertSame($name2, $countries[3]->name);
        $this->assertSame($name1, CountryFactory::table()->get($countries[0]->id)->name);
        $this->assertSame($name2, CountryFactory::table()->get($countries[1]->id)->name);
        $this->assertSame($name1, CountryFactory::table()->get($countries[2]->id)->name);
        $this->assertSame($name2, CountryFactory::table()->get($countries[3]->id)->name);
    }

    public function testSaveMultipleHasManyAssociation(): void
    {
        $amount1 = 111;
        $amount2 = 222;
        $customer = CustomerFactory::new()
            ->hasBills([
                ['amount' => $amount1],
                ['amount' => $amount2],
            ])->save();

        $this->assertSame(2, BillFactory::query()->count());
        $this->assertEquals($amount1, $customer->bills[0]->amount);
        $this->assertEquals($amount2, $customer->bills[1]->amount);

        /** @var \TestPlugin\Model\Entity\Customer $customer */
        $customer = CustomerFactory::table()->get($customer->id, contain: ['Bills']);
        $this->assertEquals($amount1, $customer->bills[0]->amount);
        $this->assertEquals($amount2, $customer->bills[1]->amount);
    }

    public function testSaveMultipleHasManyAssociationAndTimes(): void
    {
        $times = 2;
        $amount1 = 111;
        $amount2 = 222;
        $customer = CustomerFactory::new()
            ->hasBills([
                ['amount' => $amount1],
                ['amount' => $amount2],
            ], $times)->save();

        $this->assertSame(2 * $times, BillFactory::query()->count());
        $this->assertEquals($amount1, $customer->bills[0]->amount);
        $this->assertEquals($amount2, $customer->bills[1]->amount);
        $this->assertEquals($amount1, $customer->bills[2]->amount);
        $this->assertEquals($amount2, $customer->bills[3]->amount);

        $bills = BillFactory::query()->toArray();
        $this->assertEquals($amount1, $bills[0]->amount);
        $this->assertEquals($amount2, $bills[1]->amount);
        $this->assertEquals($amount1, $bills[2]->amount);
        $this->assertEquals($amount2, $bills[3]->amount);
    }

    public function testGetAssociatedFactoryWithOneDepth(): void
    {
        $street = 'Foo';
        $author = AuthorFactory::new()->with('BusinessAddress', [
            'street' => $street,
        ])->save();

        $this->assertInstanceOf(Address::class, $author->business_address);

        $author = AuthorFactory::table()->get($author->id, contain: ['BusinessAddress']);
        $this->assertSame($street, $author->business_address->street);

        // There should now be two addresses in the DB
        $this->assertSame(2, AddressFactory::query()->count());
    }

    public function testGetAssociatedFactoryWithMultipleDepth(): void
    {
        $country = 'Foo';
        $path = 'BusinessAddress.City.Countries';
        $author = AuthorFactory::new()->with($path, [
            'name' => $country,
        ])->save();

        $this->assertInstanceOf(Country::class, $author->business_address->city->country);

        $author = AuthorFactory::table()->get($author->id, contain: ['BusinessAddress.City.Countries']);
        $this->assertSame($country, $author->business_address->city->country->name);

        // There should now be two addresses in the DB
        $this->assertSame(2, AddressFactory::query()->count());
    }

    public function testGetAssociatedFactoryWithMultipleDepthWithFactory(): void
    {
        $city = 'Foo';

        $author = AuthorFactory::new()->with(
            'BusinessAddress.City',
            CityFactory::new(['name' => $city]),
        )->save();

        $this->assertInstanceOf(City::class, $author->business_address->city);

        $author = AuthorFactory::table()->get($author->id, contain: ['BusinessAddress.City']);
        $this->assertSame($city, $author->business_address->city->name);
    }

    public function testGetAssociatedFactoryWithMultipleDepthAndMultipleTimes(): void
    {
        $n = 10;
        $country = 'Foo';
        $path = 'BusinessAddress.City.Countries';
        $authors = AuthorFactory::new($n)->with($path, [
            'name' => $country,
        ])->saveMany();

        for ($i = 0; $i < $n; $i++) {
            $this->assertInstanceOf(Country::class, $authors[$i]->business_address->city->country);
        }

        $authors = AuthorFactory::query()->contain($path);
        foreach ($authors as $author) {
            $this->assertSame($country, $author->business_address->city->country->name);
        }

        // There should now be $n * 2 addresses in the DB
        $this->assertSame(2 * $n, AddressFactory::query()->count());
    }

    public function testGetAssociatedFactoryWithMultipleDepthInPlugin(): void
    {
        $name = 'Foo';
        $path = 'Bills.Customer';
        $article = ArticleFactory::new()->with($path, compact('name'))->save();

        $this->assertInstanceOf(Customer::class, $article->bills[0]->customer);

        $this->assertSame(1, ArticleFactory::query()->count());
        $this->assertSame(1, CustomerFactory::query()->count());

        $article = ArticleFactory::table()->get($article->id, contain: [$path]);
        $this->assertSame($name, $article->bills[0]->customer->name);
    }

    public function testGetAssociatedFactoryInPluginWithNumber(): void
    {
        $n = 10;
        $article = ArticleFactory::new()->with('Bills', $n)->save();

        $this->assertInstanceOf(Bill::class, $article->bills[0]);

        $bills = BillFactory::query();
        $this->assertSame($n, $bills->count());
    }

    public function testGetAssociatedFactoryInPluginWithMultipleConstructs(): void
    {
        $n = 10;
        $article = ArticleFactory::new()->with('Bills', BillFactory::new($n)->with('Customer'))->save();

        $this->assertInstanceOf(Bill::class, $article->bills[0]);
        $this->assertInstanceOf(Customer::class, $article->bills[0]->customer);

        $this->assertSame($n, BillFactory::query()->count());

        $this->assertSame($n, CustomerFactory::query()->count());
    }

    public function testWithBracketCountOnLeafFactoryArgument(): void
    {
        // `with('Alias[N]', SomeFactory::new())` previously dropped the [N]
        // because the no-dot factory-leaf branch took the passed factory
        // verbatim, bypassing AssociationBuilder::getAssociatedFactory()
        // (which is the path that applies the bracket count). Result was
        // a silent 1-row build instead of N.
        $times = 4;

        $country = CountryFactory::new()
            ->with("Cities[$times]", CityFactory::new())
            ->save();

        $country = CountryFactory::table()->get($country->id, contain: ['Cities']);
        $this->assertCount(
            $times,
            $country->cities,
            'with("Alias[N]", SomeFactory::new()) must honor [N] just like the array-data path.',
        );
    }

    public function testWithBracketCountOnLeafFactoryRespectsExplicitChainedCount(): void
    {
        // When the passed factory already chains its own count, the bracket
        // count overrides it — matching the array-path semantics where
        // `with('Cities[3]', $array)` always builds 3 regardless of how
        // many rows are inside `$array` (extras are dropped, fewer are recycled).
        $bracketCount = 3;

        $country = CountryFactory::new()
            ->with("Cities[$bracketCount]", CityFactory::new()->count(1))
            ->save();

        $country = CountryFactory::table()->get($country->id, contain: ['Cities']);
        $this->assertCount(
            $bracketCount,
            $country->cities,
            'Bracket count must take precedence over the leaf factory\'s own count().',
        );
    }

    public function testSaveMultipleHasManyAssociationAndTimesWithBrackets(): void
    {
        $times = 5;
        $street1 = 'Station Street';
        $street2 = 'Baker Street';

        // Create a country with $times cities, all having two streets of a fixed name
        $country = CountryFactory::new()->with("Cities[$times].Addresses", [
            ['street' => $street1],
            ['street' => $street2],
        ])->save();

        $this->assertSame(2 * $times, AddressFactory::query()->count());
        $country = CountryFactory::table()->get($country->id, contain: ['Cities.Addresses']);

        for ($i = 0; $i < $times; $i++) {
            $this->assertEquals($street1, $country->cities[$i]->addresses[0]->street);
            $this->assertEquals($street2, $country->cities[$i]->addresses[1]->street);
        }
    }

    public function testGetAssociatedFactoryWithReversedAssociation(): void
    {
        $name1 = 'Bar';
        $name2 = 'Foo';
        AuthorFactory::new(['name' => $name1])
            ->with('Articles.Authors', ['name' => $name2])
            ->save();

        /** @var \TestApp\Model\Entity\Article $article */
        $article = ArticleFactory::query()
            ->contain('Authors', function ($q) {
                return $q->orderBy('Authors.name');
            })
            ->first();

        $this->assertSame($name1, $article->authors[0]->name);
        $this->assertSame($name2, $article->authors[1]->name);
    }

    public function testGetAssociatedFactoryWithMultipleDepthAndWithout(): void
    {
        $author = AuthorFactory::new()
            ->with('BusinessAddress.City.Countries')
            ->with('BusinessAddress.City')
            ->without('BusinessAddress')
            ->save();

        $this->assertNull($author->business_address);
        $this->assertNull(AuthorFactory::table()->get($author->id, contain: ['BusinessAddress'])->business_address);

        // There should be only one address, city and country in the DB
        $this->assertSame(1, AddressFactory::query()->count());
        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame(1, CountryFactory::query()->count());
    }

    public function testSaveMultiplesToOneAssociationRejectsMultipleAssociatedEntities(): void
    {
        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('expects exactly 1 entity');

        CityFactory::new()->with('Countries', [
            ['name' => 'Foo1'],
            ['name' => 'Foo2'],
            ['name' => 'Foo3'],
            ['name' => 'Foo4'],
        ])->save();
    }

    public function testAssignWithoutToManyAssociation(): void
    {
        $countryExpected = 'Foo';
        $countryNotExpected = 'Bar';
        CountryFactory::new(['name' => $countryExpected])
            ->with('Cities', CityFactory::new()
            ->with('Countries', ['name' => $countryNotExpected]))
            ->save();

        $this->assertSame(1, CityFactory::query()->count());
        /** @var \TestApp\Model\Entity\City $city */
        $city = CityFactory::query()->contain('Countries')->firstOrFail();
        $this->assertSame($countryExpected, $city->country->name);
    }

    /*
     * The created city is associated with a country, which on the
     * fly get $n cities assigned. We make sure that the first city
     * is correctly associated to the country
     */

    public function testAssignWithToManyAssociation(): void
    {
        $nCities = 5;
        $city = CityFactory::new()
            ->with('Countries', CountryFactory::new()->with('Cities', $nCities))
            ->save();

        $citiesAssociatedToCountry = CountryFactory::table()->get($city->country_id, contain: ['Cities'])->cities;

        $this->assertSame($nCities + 1, count($citiesAssociatedToCountry));
        $citiesNameList = Hash::extract($citiesAssociatedToCountry, '{n}.name');
        $this->assertTrue(in_array($city->name, $citiesNameList));
    }

    /*
     * The same as above, but with belongsToMany association
     */

    public function testAssignWithBelongsToManyAssociation(): void
    {
        $nArticles = 5;
        $authorName = 'Foo';
        $article = ArticleFactory::new()
            ->with('Authors', AuthorFactory::new(['name' => 'Foo'])->with('Articles', $nArticles))
            ->save();

        $authorsAssociatedToArticle = AuthorFactory::query()
            ->matching('Articles', function ($q) use ($article) {
                return $q->where(['Articles.id' => $article->id]);
            })
            ->contain('Articles');

        $articlesAssociatedToAuthor = ArticleFactory::query()
            ->matching('Authors', function ($q) use ($authorName) {
                return $q->where(['Authors.name' => $authorName]);
            });

        $this->assertSame($nArticles + 1, $articlesAssociatedToAuthor->count());
        $this->assertSame(1, $authorsAssociatedToArticle->count());
    }

    public function testArticleWithPremiumAuthors(): void
    {
        $nPremiumAuthors = 3;
        $article = ArticleFactory::new()
            ->with('ExclusivePremiumAuthors', $nPremiumAuthors)
            ->without('Authors')
            ->save();

        $alias = PremiumAuthorsTable::ASSOCIATION_ALIAS;
        $this->assertIsArray($article[$alias]);
        foreach ($article[$alias] as $author) {
            $this->assertInstanceOf(PremiumAuthor::class, $author);
            $this->assertIsInt($author->id);
        }
        $this->assertSame($nPremiumAuthors, AuthorFactory::query()->count());
    }

    public function testCountryWith2CitiesEachOfThemWith2DifferentAddresses(): void
    {
        $street1 = 'street1';
        $street2 = 'street2';
        $country = CountryFactory::new()->with('Cities[2].Addresses', [
            ['street' => $street1],
            ['street' => $street2],
        ])->save();

        $country = CountryFactory::table()->get($country->id, contain: ['Cities.Addresses']);

        $this->assertSame(2, count($country->cities));
        foreach ($country->cities as $city) {
            $this->assertSame(2, count($city->addresses));
            $this->assertSame($street1, $city->addresses[0]->street);
            $this->assertSame($street2, $city->addresses[1]->street);
        }
    }

    public function testCountryWith2CitiesEachOfThemWithADifferentSpecifiedAddress(): void
    {
        $country = CountryFactory::new()->save();
        $street1 = 'street1';
        $street2 = 'street2';
        AddressFactory::new([
            ['street' => $street1],
            ['street' => $street2],
        ])->with('City', CityFactory::new(['country_id' => $country->id])->without('Countries'))
        ->saveMany();

        $country = CountryFactory::table()->get($country->id, contain: ['Cities.Addresses']);

        $this->checkCountryWithTwoCitiesEachWithOneAddress($country, $street1, $street2);
    }

    private function checkCountryWithTwoCitiesEachWithOneAddress(Country $country, string $street1, string $street2): void
    {
        $this->assertSame(2, count($country->cities));
        foreach ($country->cities as $city) {
            $this->assertSame(1, count($city->addresses));
        }
        $this->assertSame($street1, $country->cities[0]->addresses[0]->street);
        $this->assertSame($street2, $country->cities[1]->addresses[0]->street);
    }

    public function testCountryWith2CitiesEachOfThemWithADifferentSpecifiedAddressTheOtherWay(): void
    {
        $street1 = 'A street';
        $street2 = 'B street';

        $country = CountryFactory::new()
            ->with('Cities.Addresses', ['street' => $street1])
            ->with('Cities.Addresses', ['street' => $street2])
            ->save();

        $this->checkCountryWithTwoCitiesEachWithOneAddress($country, $street1, $street2);

        // Make sure that all was correctly persisted
        $addresses = AddressFactory::query()
            ->innerJoinWith('City.Countries', function (SelectQuery $q) use ($country) {
                return $q->where(['Countries.id' => $country->id]);
            })
            ->orderByAsc('street')
            ->toArray();

        $this->assertSame(2, count($addresses));
        $this->assertSame($street1, $addresses[0]->street);
        $this->assertSame($street2, $addresses[1]->street);

        $this->assertNotSame($addresses[0]->id, $addresses[1]->id);
    }

    public function testCountryWith2Cities(): void
    {
        $city1 = 'A city';
        $city2 = 'B city';

        $country = CountryFactory::new()
            ->with('Cities', ['name' => $city1])
            ->with('Cities', ['name' => $city2])
            ->save();

        // Make sure that all was correctly persisted
        $cities = CityFactory::query()
            ->where(['country_id' => $country->id])
            ->orderByAsc('name')
            ->toArray();

        $this->assertSame(2, count($cities));
        $this->assertSame($city1, $cities[0]->name);
        $this->assertSame($city2, $cities[1]->name);
        $this->assertNotSame($cities[0]->id, $cities[1]->id);
        $this->assertSame(2, CityFactory::query()->count());
        $this->assertSame(1, CountryFactory::query()->count());
    }

    public function testCountryWith3CitiesMultipleFactories(): void
    {
        $city1 = 'A city';
        $city2 = 'B city';
        $city3 = 'C city';

        $country = CountryFactory::new()
            ->with('Cities', [
                CityFactory::new([['name' => $city1], ['name' => $city3]])->without('Countries'),
                CityFactory::new()->setField('name', $city2)->without('Countries'),
            ])
            ->save();

        // Make sure that all was correctly persisted
        $cities = CityFactory::query()
            ->where(['country_id' => $country->id])
            ->orderByAsc('name')
            ->toArray();

        $this->assertSame(3, count($cities));
        $this->assertSame($city1, $cities[0]->name);
        $this->assertSame($city2, $cities[1]->name);
        $this->assertSame($city3, $cities[2]->name);
        $this->assertSame(3, CityFactory::query()->count());
        $this->assertSame(1, CountryFactory::query()->count());
    }

    public function testCountryWith4Cities(): void
    {
        $city1 = 'foo';
        $city2 = 'bar';
        $street1 = 'street1';
        $street2 = 'street2';

        $country = CountryFactory::new()
            ->with('Cities', ['id' => 1, 'name' => $city1])
            ->with('Cities', ['id' => 2, 'name' => $city2])
            ->with('Cities.Addresses', ['id' => 1, 'street' => $street1])
            ->with('Cities.Addresses', ['id' => 2, 'street' => $street2])
            ->save();

        // Make sure that all was correctly persisted
        $country = CountryFactory::table()->get($country->id, contain: ['Cities']);

        $this->assertSame(4, count($country->cities));
        $this->assertSame(4, CityFactory::query()->count());

        if (CountryFactory::new()->getTable()->getConnection()->config()['driver'] === Postgres::class) {
            $this->assertSame($city1, CityFactory::table()->get(1)->name);
            $this->assertSame($city2, CityFactory::table()->get(2)->name);
            $this->assertSame($street1, AddressFactory::table()->get(1)->street);
            $this->assertSame($street2, AddressFactory::table()->get(2)->street);
        }
    }

    /**
     * When an association has the same name as a virtual field,
     * the virtual field will overwrite the data prepared by the
     * associated factory
     *
     * @see Country::_getVirtualCities()
     */
    public function testAssociationWithVirtualFieldNamedIdentically(): void
    {
        $factory = CountryFactory::new()
            ->with('Cities')
            ->with('VirtualCities');

        $country = $factory->build();
        $this->assertIsString($country->cities[0]->name);
        $this->assertFalse($country->virtual_cities);

        $country = $factory->save();
        $this->assertIsString($country->cities[0]->name);
        $this->assertFalse($country->virtual_cities);

        // Only the non virtual Cities will be saved
        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame(1, CountryFactory::query()->count());
    }

    /**
     * Reproduce the issue reported here: https://github.com/vierge-noire/cakephp-fixture-factories/issues/84
     */
    public function testReproduceIssue84(): void
    {
        $articles = ArticleFactory::new(2)
            ->with('Authors[5]', ['biography' => 'Foo'])
            ->with('Bills')
            ->saveMany();

        $this->assertSame(2, count($articles));
        foreach ($articles as $article) {
            $this->assertSame(5, count($article->authors));
            foreach ($article->authors as $author) {
                $this->assertSame('Foo', $author->biography);
            }
            $this->assertSame(1, count($article->bills));
        }

        $this->assertSame(2, ArticleFactory::query()->count());
        $this->assertSame(10, AuthorFactory::query()->count());
        $this->assertSame(2, BillFactory::query()->count());
    }

    /**
     * Reproduce the issue reported here: https://github.com/vierge-noire/cakephp-fixture-factories/issues/84
     */
    public function testReproduceIssue84WithArticlesAuthors(): void
    {
        $articles = ArticleFactory::new(2)
            ->with('ArticlesAuthors[5].Authors', ['biography' => 'Foo'])
            ->with('Bills')
            ->without('Authors') // do not create the default authors
            ->saveMany();

        $this->assertSame(2, count($articles));
        foreach ($articles as $article) {
            $this->assertSame(5, count($article->articles_authors));
            foreach ($article->articles_authors as $aa) {
                /** @var \TestApp\Model\Entity\Author $author */
                $author = $aa->get('author');
                $this->assertSame('Foo', $author->biography);
            }
            $this->assertSame(1, count($article->bills));
        }

        $this->assertSame(2, ArticleFactory::query()->count());
        $this->assertSame(10, AuthorFactory::query()->count());
        $this->assertSame(2, BillFactory::query()->count());
    }

    public function testCompileEntityForToOneAssociation(): void
    {
        $name = 'FooCountry';
        $factories = [
            CityFactory::new()->with('Countries', compact('name')),
            CityFactory::new()->with('Countries', compact('name')),
            CityFactory::new()->with('Countries')->with('Countries', compact('name')),
            CityFactory::new()->with('Countries', ['name' => 'Foo'])->with('Countries', compact('name')),
        ];

        foreach ($factories as $factory) {
            $entity = $factory->build();
            $this->assertSame($name, $entity->country->name);
            $this->assertNull($entity->get('countries'));
        }

        FactoryTableRegistry::getTableLocator()->clear();
        // The CitiesTable has a belongsTo('Countries') association defined
        $this->assertTrue(CityFactory::new()->getTable()->hasAssociation('Countries'));
    }

    public function testDoNotRecreateHasOneAssociationWhenInjectingEntityOneLevelDepth(): void
    {
        $city = CityFactory::new()->with('Countries')->save();
        $cityCountryId = $city->country_id;
        $cityCountryName = $city->country->name;

        CityFactory::new($city)->save();

        $this->assertSame($cityCountryId, $city->country_id);
        $this->assertSame($cityCountryName, $city->country->name);
        $this->assertSame(1, CountryFactory::query()->count());
        $this->assertSame(1, CityFactory::query()->count());
    }

    public function testDoNotRecreateHasOneAssociationWhenInjectingEntityTwoLevelDepth(): void
    {
        $city = CityFactory::new()->with('Countries')->save();
        $cityCountryId = $city->country_id;
        $cityCountryName = $city->country->name;

        AddressFactory::new()->with('City', $city)->save();

        $this->assertSame($cityCountryId, $city->country_id);
        $this->assertSame($cityCountryName, $city->country->name);
        $this->assertSame(1, CountryFactory::query()->count());
        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame(1, AddressFactory::query()->count());
    }

    public function testDoNotRecreateHasOneAssociationWhenInjectingEntityThreeLevelDepth(): void
    {
        $address = AddressFactory::new()->with('City.Countries')->save();

        AuthorFactory::new()->with('Address', $address)->save();

        $this->assertSame(1, CountryFactory::query()->count());
        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame(1, AddressFactory::query()->count());
        $this->assertSame(1, AuthorFactory::query()->count());
    }

    public function testDoNotRecreateHasManyAssociationWhenInjectingEntityOneLevelDepth(): void
    {
        $country = CountryFactory::new()->with('Cities')->save();
        $cityId = $country->cities[0]->id;
        $cityName = $country->cities[0]->name;

        CountryFactory::new($country)->save();

        $this->assertSame($cityId, $country->cities[0]->id);
        $this->assertSame($cityName, $country->cities[0]->name);
        $this->assertSame(1, CountryFactory::query()->count());
        $this->assertSame(1, CityFactory::query()->count());
    }

    public function testAssociationsInSubFolders(): void
    {
        $name = 'Foo';
        $country = CountryFactory::new()
            ->with('Cities', SubCityFactory::new(compact('name')))
            ->build();

        $this->assertSame($name, $country->cities[0]->name);
    }

    /**
     * Two `->with('SameAlias', F)` calls on a to-many branch BOTH contribute
     * entities (DataCompiler appends), but `AssociationBuilder::$associations`
     * only kept the LAST factory — so `getMarshallerOptions()->associated`
     * silently dropped nested-marshaller config (e.g. nested `with()` /
     * `accessibleFields`) from the first branch. The marshaller-routed outer
     * patch (DataCompiler.php:449) then ran without that config.
     *
     * Fix: marshaller options must merge across every factory ever added
     * under a given alias.
     */
    public function testGetMarshallerOptionsMergesAssociatedConfigAcrossDuplicateAliases(): void
    {
        // Two with('Authors', ...) on an Article: F1 nests a non-back-link
        // BusinessAddress, F2 is bare. Merged 'associated' must keep F1's
        // BusinessAddress config — without the fix only F2 (last-wins) was
        // reflected in marshaller options. (BusinessAddress is chosen because
        // it is NOT the back-link Authors->Articles, which would otherwise be
        // stripped by removeAssociationForToOneFactory.)
        $factory = ArticleFactory::new()
            ->with('Authors', AuthorFactory::new()->with('BusinessAddress', AddressFactory::new()))
            ->with('Authors', AuthorFactory::new());

        $options = $factory->getMarshallerOptions();

        $this->assertArrayHasKey('associated', $options);
        $this->assertArrayHasKey('Authors', $options['associated']);
        $authorsOptions = $options['associated']['Authors'];
        $this->assertArrayHasKey('associated', $authorsOptions);
        $this->assertArrayHasKey(
            'BusinessAddress',
            $authorsOptions['associated'],
            "Nested 'BusinessAddress' from the first with('Authors', …) must survive a second with('Authors', …).",
        );
    }

    /**
     * For TO-ONE branches (belongsTo / hasOne), DataCompiler's
     * mergeWithToOne picks the LAST factory only — `$data[$count - 1]`. The
     * marshaller config must follow the same "last wins" precedence, NOT
     * union across history; otherwise stale config from a replaced F1
     * leaks into patchEntity() and accepts/persists fields F2 explicitly
     * dropped.
     */
    public function testGetMarshallerOptionsKeepsLastWinsForDuplicateToOneAliases(): void
    {
        // Bare F2 establishes the "what F2 alone produces" baseline (it
        // includes configure-default associations, that's fine — we just
        // want to confirm F1's extras did NOT leak into the merged result).
        $f2 = AddressFactory::new();
        $f2Baseline = $f2->getMarshallerOptions();

        // F1 has a marker option F2 does not — `setMarshallerOptions()`
        // adds a synthetic key so we can detect leakage cleanly.
        $f1 = AddressFactory::new()->setMarshallerOptions(['_leak_probe' => true]);

        $factory = AuthorFactory::new()
            ->with('Address', $f1)
            ->with('Address', $f2);

        $options = $factory->getMarshallerOptions();

        $this->assertSame(
            $f2Baseline,
            $options['associated']['Address'],
            'Duplicate belongsTo alias is last-wins: F1 was replaced, so the merged '
            . 'marshaller config must equal F2 standalone — not a union that leaks F1.',
        );
        $this->assertArrayNotHasKey(
            '_leak_probe',
            $options['associated']['Address'],
            'F1 leak probe must not surface on the merged Address options.',
        );
    }
}
