<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\AssociationBuilderException;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\ArticlesAuthorFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\CustomerFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use InvalidArgumentException;
use TestApp\Model\Entity\Country;

/**
 * Behavioural tests for `BaseFactory::recycle()`.
 *
 * recycle() reuses an already-built entity wherever any belongsTo association
 * in the build graph targets that entity's source table — closing the silent
 * N-duplicate-parent gap.
 */
class BaseFactoryRecycleTest extends TestCase
{
    use TruncateDirtyTables;

    public function testRecycleReturnsCloneAndIsImmutable(): void
    {
        $country = CountryFactory::new(['name' => 'Original'])->save();

        $base = CityFactory::new();
        $recycled = $base->recycle($country);

        $this->assertNotSame($base, $recycled, 'recycle() must return a clone, never mutate.');
    }

    public function testRecycleSubstitutesExplicitBelongsTo(): void
    {
        $country = CountryFactory::new(['name' => 'Recycled Country'])->save();

        $city = CityFactory::new()
            ->forCountries() // would normally create a fresh Country
            ->recycle($country)
            ->save();

        $this->assertNotNull($city->id);
        $this->assertSame(
            $country->id,
            $city->country_id,
            'City built with forCountries() + recycle($country) must reuse the recycled country.',
        );
        $this->assertSame(
            1,
            CountryFactory::query()->count(),
            'Only one country should exist in the database — the recycled one.',
        );
    }

    public function testRecycleAppliesAcrossCountedBatch(): void
    {
        $country = CountryFactory::new(['name' => 'Shared'])->save();

        $cities = CityFactory::new()
            ->count(5)
            ->forCountries()
            ->recycle($country)
            ->saveMany();

        $this->assertCount(5, $cities);
        foreach ($cities as $city) {
            $this->assertSame($country->id, $city->country_id);
        }

        $this->assertSame(1, CountryFactory::query()->count());
    }

    public function testRecyclePropagatesToChildFactories(): void
    {
        $country = CountryFactory::new(['name' => 'Propagated'])->save();

        // AddressFactory: 3 addresses, each with a City, each City with a Country.
        // recycle on the ROOT factory must flow down so the nested CityFactory
        // substitutes the recycled country instead of building fresh ones.
        $addresses = AddressFactory::new()
            ->count(3)
            ->with('City', CityFactory::new()->forCountries())
            ->recycle($country)
            ->saveMany();

        $this->assertCount(3, $addresses);
        foreach ($addresses as $address) {
            $this->assertNotNull($address->city);
            $this->assertSame(
                $country->id,
                $address->city->country_id,
                'Nested City must inherit recycle from root AddressFactory.',
            );
        }

        $this->assertSame(
            1,
            CountryFactory::query()->count(),
            'Only one country should exist — the recycled one, reused for every nested City.',
        );
    }

    public function testRecycleIgnoresAssociationsNotInTree(): void
    {
        // Address has no belongsTo Country; recycling a Country here is
        // a silent no-op. The build must still succeed.
        $unused = CountryFactory::new(['name' => 'Unused'])->save();

        $address = AddressFactory::new()
            ->recycle($unused)
            ->save();

        $this->assertNotNull($address->id);
    }

    public function testRecycleSubstitutesMultipleAliasesTargetingSameTable(): void
    {
        // AuthorsTable has two belongsTo Addresses aliases: Address + BusinessAddress.
        // recycle($address) substitutes BOTH because both have the same source.
        // (Per-alias control belongs to with('Address', $entity) directly.)
        $address = AddressFactory::new()->save();

        $author = AuthorFactory::new()
            ->with('Address', AddressFactory::new())
            ->with('BusinessAddress', AddressFactory::new())
            ->recycle($address)
            ->save();

        $this->assertSame($address->id, $author->address_id);
        $this->assertSame($address->id, $author->business_address_id);
        $this->assertSame(1, AddressFactory::query()->count());
    }

    public function testRecycleAcceptsMultipleEntitiesVariadically(): void
    {
        $country = CountryFactory::new(['name' => 'Country'])->save();
        $address = AddressFactory::new()->save();

        // The City build chains forCountries(). recycle() takes both entities
        // — only the Country target actually substitutes (the Address recycle
        // is a no-op here because City has no belongsTo Addresses).
        $city = CityFactory::new()
            ->forCountries()
            ->recycle($country, $address)
            ->save();

        $this->assertSame($country->id, $city->country_id);
    }

    public function testRecycleChainedCallsMergeRecycleMap(): void
    {
        $country = CountryFactory::new(['name' => 'C'])->save();
        $address = AddressFactory::new()->save();

        // Two separate recycle() calls should both be active.
        $city = CityFactory::new()
            ->forCountries()
            ->recycle($country)
            ->recycle($address) // unrelated — silently ignored
            ->save();

        $this->assertSame($country->id, $city->country_id);
    }

    public function testExplicitWithEntityWinsOverRecycle(): void
    {
        // The user's per-alias choice (with('Alias', $entity)) must NOT be
        // silently overridden by recycle() on the parent factory.
        $home = AddressFactory::new()->save();
        $office = AddressFactory::new()->save();

        $author = AuthorFactory::new()
            ->with('Address', $home)
            ->with('BusinessAddress', $office)
            ->recycle($home) // would otherwise also override BusinessAddress
            ->save();

        $this->assertSame($home->id, $author->address_id, 'Explicit Address wins.');
        $this->assertSame(
            $office->id,
            $author->business_address_id,
            'BusinessAddress explicitly set to $office must not be overridden by recycle($home).',
        );
    }

    public function testRecycleMatchesEntityLoadedViaAlias(): void
    {
        // When an entity is fetched via an aliased belongsTo (e.g. through
        // `contain: ['BusinessAddress']`), its `getSource()` is the association
        // *alias* ('BusinessAddress'), not the underlying table ('Addresses').
        // recycle() must still match it for the same alias on another build.
        $seeded = AuthorFactory::new()
            ->with('BusinessAddress', AddressFactory::new())
            ->save();
        $loaded = AuthorFactory::table()
            ->get($seeded->id, contain: ['BusinessAddress'])
            ->business_address;
        $this->assertNotNull($loaded);

        $other = AuthorFactory::new()
            ->with('BusinessAddress', AddressFactory::new())
            ->recycle($loaded)
            ->save();

        $this->assertSame(
            $loaded->id,
            $other->business_address_id,
            'recycle() must match entities whose source is an association alias (here `BusinessAddress`).',
        );
    }

    public function testRecycleMatchesPluginPrefixedAssociation(): void
    {
        // BillsTable belongsTo Customer via className `TestPlugin.Customers`.
        // The recycled entity's source includes the plugin prefix too — both
        // sides must normalize to the same canonical name for the match to fire.
        $customer = CustomerFactory::new(['name' => 'Recycled'])->save();

        $bill = BillFactory::new()
            ->forCustomer()
            ->recycle($customer)
            ->save();

        $this->assertSame(
            $customer->id,
            $bill->customer_id,
            'Plugin-prefixed `Customer` association must match recycle($customer) keyed by `Customers`.',
        );
    }

    public function testStaleExplicitWithDoesNotBlockLaterRecycle(): void
    {
        // `with('Address', $home)` is later overridden by `with('Address', AddressFactory::new())`.
        // The active (last-written) factory is the clean one, so recycle($office) on the
        // parent must still substitute — stale earlier entries do not lock the branch.
        $home = AddressFactory::new()->save();
        $office = AddressFactory::new()->save();

        $author = AuthorFactory::new()
            ->with('Address', $home)
            ->with('Address', AddressFactory::new()) // overrides the prior $home line
            ->recycle($office)
            ->save();

        $this->assertSame(
            $office->id,
            $author->address_id,
            'Stale prior with($home) must not prevent recycle($office) from substituting the active branch.',
        );
    }

    public function testRecycleStillEnforcesSingularToOneCardinality(): void
    {
        // A plural factory on a to-one association is a programmer error
        // regardless of whether recycle would substitute the result.
        // The registration-time AssociationBuilder guard fires before
        // recycle even comes into play.
        $country = CountryFactory::new()->save();

        $this->expectException(AssociationBuilderException::class);
        $this->expectExceptionMessageMatches('/cannot be multiple/');

        CityFactory::new()
            ->with('Countries', CountryFactory::new()->count(2))
            ->recycle($country)
            ->save();
    }

    public function testRecycleRequiresPersistedEntities(): void
    {
        // recycle()'s fast path skips the child factory's build, so an unsaved
        // entity can't reuse the child's persistence pipeline (PK assignment,
        // unique-property marker, etc.). Refuse loudly with a clear message.
        $built = CountryFactory::new(['name' => 'Built only'])->build();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already-persisted|isNew/');

        CityFactory::new()->forCountries()->recycle($built);
    }

    public function testRecycleDoesNotOverrideCustomizedChildFactory(): void
    {
        // When the user passes a child factory with its own chains, that
        // explicit setup wins over recycle. AuthorsTable belongsTo Address;
        // we configure a per-author Address via `with('Address', AddressFactory::new()->forCity())`
        // and recycle a *different* address. Recycle must NOT override
        // the customized branch.
        $recycled = AddressFactory::new()->save();

        $author = AuthorFactory::new()
            ->with('Address', AddressFactory::new()->forCity())
            ->recycle($recycled)
            ->save();

        $this->assertNotSame(
            $recycled->id,
            $author->address_id,
            'A child factory with user-set chains must not be overridden by recycle.',
        );
    }

    public function testRecycleRejectsEntityWithoutSource(): void
    {
        // Manually constructed entity without setSource() — recycle should refuse.
        $detached = new Country(['name' => 'Detached']);
        $detached->setSource('');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/source/i');

        CityFactory::new()->recycle($detached);
    }

    /**
     * Two entities for the same source table in ONE recycle() call would
     * silently keep only the last — almost always a typo. Reject loudly.
     * (Across separate `->recycle()->recycle()` calls last-wins still works;
     * see {@see self::testSeparateRecycleCallsOnSameSourceLastWins}.)
     */
    public function testRecycleRejectsTwoEntitiesForSameSourceInOneCall(): void
    {
        $a = CityFactory::new()->save();
        $b = CityFactory::new()->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/same source table/');

        AddressFactory::new()->recycle($a, $b);
    }

    /**
     * Two separate `->recycle()` calls on the same source table is an
     * intentional update of the recycle map — last wins is preserved.
     */
    public function testSeparateRecycleCallsOnSameSourceLastWins(): void
    {
        $first = CityFactory::new(['name' => 'first'])->save();
        $second = CityFactory::new(['name' => 'second'])->save();

        $address = AddressFactory::new()
            ->recycle($first)
            ->recycle($second)
            ->save();

        $this->assertSame($second->id, $address->city_id);
    }

    public function testRecyclePropagatesThroughHasManyChildToSiblingBelongsTo(): void
    {
        // recycle()'s propagation contract: the recycle map flows DOWN through
        // every child factory in the build graph, including ones reached
        // through hasMany / belongsToMany edges. ArticlesAuthors is the
        // junction table for the Articles ↔ Authors many-to-many; built via
        // ArticlesAuthorFactory it has TWO belongsTo edges:
        //   - belongsTo Articles (stripped at build time — the parent IS the
        //     article)
        //   - belongsTo Authors (the sibling edge we want recycle to hit)
        //
        // Note the explicit without('Authors'): ArticleFactory.configure()
        // adds the BTM Authors default via hasAuthors(2). Recycle DOES NOT
        // substitute to-many edges — only belongsTo. To isolate the
        // belongsTo-inside-junction propagation under test, drop the
        // default BTM so we're not asserting on a mix of recycled
        // (junction-side) and fresh (BTM-side) authors.
        $author = AuthorFactory::new(['name' => 'Recycled Through HasMany'])->save();
        $authorsBefore = AuthorFactory::query()->count();

        $article = ArticleFactory::new()
            ->without('Authors')
            ->with('ArticlesAuthors', ArticlesAuthorFactory::new()->with('Authors'))
            ->recycle($author)
            ->save();

        $this->assertNotNull($article->id);

        $junctionRows = ArticlesAuthorFactory::query()
            ->where(['article_id' => $article->id])
            ->toArray();
        $this->assertCount(1, $junctionRows, 'Exactly one junction row for the article.');
        $this->assertSame(
            $author->id,
            $junctionRows[0]->author_id,
            'Junction row must reference the recycled $author, not a freshly built one.',
        );

        $this->assertSame(
            $authorsBefore,
            AuthorFactory::query()->count(),
            'recycle($author) must propagate through hasMany ArticlesAuthors into '
            . 'ArticlesAuthor.belongsTo Authors — no fresh author should be inserted.',
        );
    }

    public function testRecycleDoesNotSubstituteDirectBelongsToManyChild(): void
    {
        // Contract boundary: recycle() substitutes BELONGS-TO edges only.
        // A direct belongsToMany child (here ArticleFactory.hasAuthors(2)
        // adds Authors via the BTM edge) keeps its factory-built identity —
        // the recycle map flows DOWN into it so any belongsTo branch it
        // contains may substitute, but the to-many entity itself is never
        // swapped for the recycled one. This makes the audit-flagged
        // hasMany-propagation test (above) load-bearing in the opposite
        // direction too: without this boundary, recycle would silently
        // collapse legitimate to-many builds into the recycle target.
        $existing = AuthorFactory::new(['name' => 'Existing'])->save();
        $authorsBefore = AuthorFactory::query()->count();

        // ArticleFactory.configure() calls hasAuthors(2) by default.
        $article = ArticleFactory::new()
            ->recycle($existing)
            ->save();

        // The 2 default BTM Authors must each be a freshly inserted author —
        // NOT $existing duplicated twice.
        $this->assertNotNull($article->id);
        $this->assertSame(
            $authorsBefore + ArticleFactory::DEFAULT_NUMBER_OF_AUTHORS,
            AuthorFactory::query()->count(),
            'BTM Authors children must build fresh; recycle does not substitute belongsToMany edges.',
        );
    }

    public function testWithBracketCountAndRecycleDoesNotSubstituteToManyChildren(): void
    {
        // The bracket count syntax (`with('Alias[N]', ...)`) is per the
        // associations guide a hasMany cardinality control: build N rows of
        // the alias. recycle() only substitutes belongsTo edges, so the N
        // hasMany children must all be freshly built — none of them collapse
        // into the recycled entity even though it targets the same source
        // table. Pins both contracts in one shot.
        $country = CountryFactory::new()->save();
        $existingCity = CityFactory::new()->save();
        $citiesBefore = CityFactory::query()->count();

        $country = CountryFactory::table()->get($country->id);
        $result = CityFactory::table()->find()->where(['country_id' => $country->id])->count();
        $this->assertSame(0, $result, 'No cities exist for this country yet.');

        $country = CountryFactory::new()
            ->with('Cities[3]', CityFactory::new())
            ->recycle($existingCity)
            ->save();

        // 3 fresh Cities built — `Cities[3]` is the cardinality, recycle does
        // not substitute on the to-many edge. So total cities = before + 3.
        $this->assertSame(
            $citiesBefore + 3,
            CityFactory::query()->count(),
            'Bracket-count hasMany children must build fresh regardless of recycle().',
        );
        $this->assertSame(
            3,
            CityFactory::query()->where(['country_id' => $country->id])->count(),
            'Exactly 3 cities composed under the new country.',
        );
    }
}
