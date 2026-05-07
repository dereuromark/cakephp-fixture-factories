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
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Error\UniquenessException;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

class BaseFactoryUniqueEntitiesTest extends TestCase
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

    public function testGetUniqueProperties(): void
    {
        $this->assertSame(
            ['unique_stamp'],
            CountryFactory::new()->getUniqueProperties(),
        );
        $this->assertSame(
            [],
            AuthorFactory::new()->getUniqueProperties(),
        );
    }

    public function testDetectDuplicateAndThrowErrorWhenPrimary(): void
    {
        $this->expectException(PersistenceException::class);
        $unique_stamp = 'Foo';
        CountryFactory::new(compact('unique_stamp'))->save();
        CountryFactory::new(compact('unique_stamp'))->save();
    }

    public function testSaveEntitiesWithTheSameId(): void
    {
        $this->expectException(PersistenceException::class);
        AuthorFactory::new(['id' => 1])->save();
        AuthorFactory::new(['id' => 1])->save();
    }

    public function testNoUniquenessCreatesMultipleEntities(): void
    {
        $nCities = 3;
        CityFactory::new($nCities)->with('Countries')->saveMany();
        $this->assertSame($nCities, CityFactory::query()->count());
        $this->assertSame($nCities, CountryFactory::query()->count());
    }

    public function testDetectDuplicateInAssociation(): void
    {
        $unique_stamp = 'Foo';
        $originalCountry = CountryFactory::new([
            'unique_stamp' => $unique_stamp,
            'name' => 'First save',
        ])->save();

        $city = CityFactory::new()->forCountries([
            'unique_stamp' => $unique_stamp,
            'name' => 'Second save',
        ])->save();

        $newCountry = $city->get('country');

        $this->assertSame($originalCountry->id, $newCountry->id);
        $this->assertSame($city->get('country_id'), $newCountry->id);
        $this->assertSame($originalCountry->unique_stamp, $unique_stamp);
        $this->assertSame($newCountry->unique_stamp, $unique_stamp);
        $this->assertSame(1, CountryFactory::query()->count());
    }

    /**
     * @Given an author is created
     *
     * @When an article with that same author is created
     *
     * @Then the author is not created again, but updated.
     */
    public function testDetectDuplicatePrimaryKeyInAssociation(): void
    {
        $authorId = rand();
        $originalAuthor = AuthorFactory::new([
            'id' => $authorId,
        ])->save();

        $authorName = 'Foo';
        $article = ArticleFactory::new()->with('Authors', [
            'id' => $authorId,
            'name' => $authorName,
        ])->save();

        $newAuthor = $article->get('authors')[0];

        $this->assertSame($originalAuthor->id, $newAuthor->id);
        $this->assertSame($authorName, $newAuthor->name);
        $this->assertSame(1, AuthorFactory::query()->count());
        $this->assertSame(1, AddressFactory::query()->count());
    }

    /**
     * @Given we instantiate a factory with a unique field
     * with two entries.
     *
     * @When we get entities
     *
     * @Then An Exception is thrown
     */
    public function testDetectDuplicateInInstantiation(): void
    {
        $this->expectException(UniquenessException::class);
        $factoryName = CountryFactory::class;
        $this->expectExceptionMessage("Error in `{$factoryName}`. The uniqueness of `unique_stamp` was not respected.");

        $unique_stamp = 'Foo';

        CountryFactory::new([
            compact('unique_stamp'),
            compact('unique_stamp'),
        ])->buildMany();
    }

    /**
     * @Given we instantiate a factory with a unique field
     * with two entries.
     *
     * @When we get entities
     *
     * @Then An Exception is thrown
     */
    public function testDetectDuplicateInInstantiationWithTimes(): void
    {
        $this->expectException(UniquenessException::class);
        $factoryName = CountryFactory::class;
        $this->expectExceptionMessage("Error in `{$factoryName}`. The uniqueness of `unique_stamp` was not respected.");

        $unique_stamp = 'Foo';

        CountryFactory::new(compact('unique_stamp'), 2)->buildMany();
    }

    /**
     * @Given we instantiate a factory with a unique field
     * with two entries.
     *
     * @When we get entities
     *
     * @Then An Exception is thrown.
     */
    public function testDetectDuplicateInPatchWithTimes(): void
    {
        $this->expectException(UniquenessException::class);
        $factoryName = CountryFactory::class;
        $this->expectExceptionMessage("Error in `{$factoryName}`. The uniqueness of `unique_stamp` was not respected.");

        $unique_stamp = 'Foo';

        CountryFactory::new(2)->state(compact('unique_stamp'))->buildMany();
    }

    /**
     * @Given we instantiate a factory with a unique field
     * with two entries.
     *
     * @When we persist
     *
     * @Then An Exception is thrown.
     */
    public function testDetectDuplicateInInstantiationPersist(): void
    {
        $this->expectException(UniquenessException::class);
        $factoryName = CountryFactory::class;
        $this->expectExceptionMessage("Error in `{$factoryName}`. The uniqueness of `unique_stamp` was not respected.");

        $unique_stamp = 'Foo';

        CountryFactory::new([
            compact('unique_stamp'),
            compact('unique_stamp'),
        ])->save();
    }

    /**
     * @Given we instantiate an associated factory with a unique field
     * with two entries
     *
     * @When we get entities
     *
     * @Then An exception is thrown.
     */
    public function testDetectDuplicateInInstantiationWithTimesInAssociation(): void
    {
        $this->expectException(UniquenessException::class);
        $factoryName = CityFactory::class;
        $this->expectExceptionMessage("Error in `{$factoryName}`. The uniqueness of `virtual_unique_stamp` was not respected.");

        $virtual_unique_stamp = 'virtual_unique_stamp';

        CountryFactory::new()->with('Cities', [
            compact('virtual_unique_stamp'),
            compact('virtual_unique_stamp'),
        ])->buildMany();
    }

    /**
     * @Given we instantiate an associated factory with a unique field
     * with two entries provided by numerically
     *
     * @When we get entities
     *
     * @Then An exception is thrown.
     */
    public function testDetectDuplicateInInstantiationWithTimesInAssociationNumeric(): void
    {
        $this->expectException(UniquenessException::class);
        $factoryName = CityFactory::class;
        $this->expectExceptionMessage("Error in `{$factoryName}`. The uniqueness of `virtual_unique_stamp` was not respected.");

        $virtual_unique_stamp = 'virtual_unique_stamp';

        CountryFactory::new()->with('Cities[2]', compact('virtual_unique_stamp'))->buildMany();
    }

    /**
     * @Given we create n countries with a common cities (imagine...)
     *
     * @When we persist
     *
     * @Then only on single city should be persisted and be associated
     * to all n countries.
     */
    public function testCreateSeveralEntitiesWithSameAssociationHasMany(): void
    {
        $virtual_unique_stamp = 'foo';

        // HasMany
        $nCountries = 3;
        $countries = CountryFactory::new($nCountries)
            ->with('Cities', compact('virtual_unique_stamp'))
            ->saveMany();

        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame($nCountries, CountryFactory::query()->count());
        /** @var \TestApp\Model\Entity\City $city */
        $city = CityFactory::query()->first();
        $cityId = $city->id;
        foreach ($countries as $country) {
            $this->assertSame($virtual_unique_stamp, $country->cities[0]->virtual_unique_stamp);
            $this->assertSame($cityId, $country->cities[0]->id);
        }
    }

    /**
     * @Given we create n cities within a country
     *
     * @When we persist
     *
     * @Then only on single country should be persisted and be associated
     * to all n cities.
     */
    public function testCreateSeveralEntitiesWithSameAssociationBelongsTo(): void
    {
        $unique_stamp = 'foo';

        // BelongsTo
        $nCities = 3;
        $cities = CityFactory::new($nCities)
            ->with('Countries', compact('unique_stamp'))
            ->saveMany();

        $this->assertSame(1, CountryFactory::query()->count());
        $this->assertSame($nCities, CityFactory::query()->count());
        $countryId = CountryFactory::query()->first()->get('id');
        foreach ($cities as $city) {
            $this->assertSame($unique_stamp, $city->country->unique_stamp);
            $this->assertSame($countryId, $city->country_id);
        }
    }

    /**
     * @Given we create n cities within a country
     *
     * @When we persist
     *
     * @Then only on single country should be persisted and be associated
     * to all n cities.
     */
    public function testCreateSeveralEntitiesWithSameAssociationBelongsToWithChainedWith(): void
    {
        $unique_stamp = 'foo';

        // BelongsTo
        $nCities = 3;
        $countryName = 'Foo';
        $cities = CityFactory::new($nCities)
            ->with('Countries', compact('unique_stamp'))
            ->with('Countries', compact('unique_stamp') + ['name' => $countryName])
            ->saveMany();

        $this->assertSame(1, CountryFactory::query()->count());
        $this->assertSame($nCities, CityFactory::query()->count());
        /** @var \TestApp\Model\Entity\Country $retrievedCountry */
        $retrievedCountry = CountryFactory::query()->first();
        $countryId = $retrievedCountry->id;
        $this->assertSame($countryName, $retrievedCountry->name);
        foreach ($cities as $city) {
            $this->assertSame($unique_stamp, $city->country->unique_stamp);
            $this->assertSame($countryId, $city->country_id);
        }
    }
}
