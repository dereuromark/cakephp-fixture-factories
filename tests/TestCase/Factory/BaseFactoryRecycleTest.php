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
}
