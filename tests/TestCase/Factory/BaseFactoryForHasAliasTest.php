<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use RuntimeException;

/**
 * `BaseFactory::for()` and `BaseFactory::has()` accept an optional alias
 * parameter so callers can disambiguate explicitly when a table has multiple
 * associations targeting the same model (Authors -> Address / BusinessAddress,
 * Countries -> Cities / VirtualCities, etc.).
 *
 * The auto-resolved single-arg form keeps working for unambiguous schemas.
 */
class BaseFactoryForHasAliasTest extends TestCase
{
    use TruncateDirtyTables;

    public function testForAcceptsExplicitAlias(): void
    {
        // AuthorsTable: belongsTo Address AND BusinessAddress (both -> Addresses).
        // Single-arg for(AddressFactory::new()) throws (ambiguous); the alias
        // overload disambiguates.
        $home = AddressFactory::new(['street' => 'Home'])->save();
        $office = AddressFactory::new(['street' => 'Office'])->save();

        $author = AuthorFactory::new()
            ->for(AddressFactory::from($home), 'Address')
            ->for(AddressFactory::from($office), 'BusinessAddress')
            ->save();

        $this->assertSame($home->id, $author->address_id);
        $this->assertSame($office->id, $author->business_address_id);
    }

    public function testHasAcceptsExplicitAlias(): void
    {
        // CountriesTable: hasMany Cities AND VirtualCities (both -> Cities).
        $country = CountryFactory::new()
            ->has(CityFactory::new()->count(2), 'Cities')
            ->save();

        $this->assertCount(2, $country->cities);
    }

    public function testHasAliasComposesWithPivotData(): void
    {
        // Pivot data is the 3rd argument when the alias is given explicitly.
        // (Authors-Articles is a belongsToMany via articles_authors.)
        $author = AuthorFactory::new()
            ->has(
                ArticleFactory::new(['title' => 'A'])->without('Authors'),
                'Articles',
                [],
            )
            ->save();

        $this->assertNotNull($author->id);
    }

    public function testForFallsBackToAutoResolveWhenAliasIsNull(): void
    {
        // Cities belongsTo a single Countries — single-arg form keeps working.
        $country = CountryFactory::new(['name' => 'Singletonia'])->save();

        $city = CityFactory::new()
            ->for(CountryFactory::from($country))
            ->save();

        $this->assertSame($country->id, $city->country_id);
    }

    public function testForWithUnknownAliasRaisesClearError(): void
    {
        // Passing a misspelled alias should throw a clear error pointing
        // at the missing association rather than silently no-op'ing.
        $this->expectExceptionMessageMatches('/Nonexistent|association/i');

        AuthorFactory::new()
            ->for(AddressFactory::new(), 'Nonexistent')
            ->save();
    }

    public function testHasUnknownAliasRaisesClearError(): void
    {
        $this->expectExceptionMessageMatches('/Nonexistent|association/i');

        CountryFactory::new()
            ->has(CityFactory::new(), 'Nonexistent')
            ->save();
    }

    public function testForRejectsHasManyAlias(): void
    {
        // `for()` is the belongsTo helper. If the caller passes an alias that
        // actually points at a has* association, fail fast instead of silently
        // building the wrong graph.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/expected belongsTo|has\*/');

        // Authors hasMany Articles via the ArticlesAuthors join. Calling
        // `for(ArticleFactory::new(), 'Articles')` is a categorical mistake.
        AuthorFactory::new()
            ->for(ArticleFactory::new()->without('Authors'), 'Articles')
            ->save();
    }

    public function testHasRejectsBelongsToAlias(): void
    {
        // Inverse: `has()` is the has*/belongsToMany helper. A belongsTo alias
        // here is also a categorical mistake.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/expected has\*|belongsTo/');

        AuthorFactory::new()
            ->has(AddressFactory::new(), 'Address')
            ->save();
    }
}
