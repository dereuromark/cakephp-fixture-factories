<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\RequiredParentsAuthorConfiguredFactory;
use CakephpFixtureFactories\Test\Factory\RequiredParentsAuthorFactory;
use CakephpFixtureFactories\Test\Factory\RequiredParentsCompositeOptInTableWithoutModelFactory;
use CakephpFixtureFactories\Test\Factory\RequiredParentsExcludeAuthorFactory;
use CakephpFixtureFactories\Test\Factory\RequiredParentsForeignKeyFalseOptInFactory;
use CakephpFixtureFactories\Test\Factory\RequiredParentsOverrideAuthorFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use InvalidArgumentException;
use ReflectionMethod;
use TestApp\Test\Factory\AuthorFactory;
use Throwable;

/**
 * Behavioural tests for `BaseFactory::withRequiredParents()` — the ergonomic
 * counterpart to the FK-in-definition() detector (`strictDefinition`).
 *
 * It composes every belongsTo whose single scalar FK column is NOT NULL,
 * recursively, so a built row satisfies its NOT NULL FK constraints without
 * hand-written `->with()` boilerplate. Composite-key / `foreignKey => false`
 * associations are never auto-resolved (PR #85 brittle cases) — only the
 * override hook can opt them in.
 */
class BaseFactoryWithRequiredParentsTest extends TestCase
{
    use TruncateDirtyTables;

    protected function tearDown(): void
    {
        // Tests that augment a factory's root table with extra associations
        // (cycle / custom-join / shared-PK cases) must not leak them into the
        // next test: clear BOTH the app locator and the fixture-factories one
        // (the factory resolves its table through the latter).
        TableRegistry::getTableLocator()->clear();
        FactoryTableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    /**
     * The Authors table requires `address_id` (NOT NULL belongsTo Address),
     * which in turn requires `city_id` (NOT NULL belongsTo City), which
     * requires `country_id` (NOT NULL belongsTo Country). One call composes
     * the whole NOT NULL chain so the row persists.
     */
    public function testComposesRequiredBelongsToChain(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents()
            ->save();

        $this->assertNotNull($author->id);
        $this->assertNotNull($author->address_id, 'Required Address must be composed.');
        $this->assertSame(1, AddressFactory::query()->count());
        $this->assertSame(1, CityFactory::query()->count(), 'Address.city_id chain must be satisfied.');
        $this->assertSame(1, CountryFactory::query()->count(), 'City.country_id chain must be satisfied.');
    }

    /**
     * `business_address_id` is nullable, so BusinessAddress is NOT a required
     * parent and must not be silently fabricated.
     */
    public function testNullableForeignKeyIsNotComposed(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents()
            ->save();

        $this->assertNull(
            $author->business_address_id,
            'Nullable business_address_id must not be auto-composed.',
        );
        // One Address only — for the required `address_id`, not BusinessAddress.
        $this->assertSame(1, AddressFactory::query()->count());
    }

    /**
     * `withRequiredParents()` returns a clone and never mutates the receiver.
     */
    public function testReturnsCloneAndIsImmutable(): void
    {
        $base = RequiredParentsAuthorFactory::new();
        $withParents = $base->withRequiredParents();

        $this->assertNotSame($base, $withParents);
    }

    /**
     * An alias in `$except` is skipped: the caller pins that FK literally for
     * a column-scope assertion. Here Address is excepted and the FK supplied
     * explicitly; the deeper chain is therefore not built at all.
     */
    public function testExceptSkipsNamedAssociation(): void
    {
        $address = AddressFactory::new()->save();
        $cityCountBefore = CityFactory::query()->count();

        $author = RequiredParentsAuthorFactory::new(['address_id' => $address->id])
            ->withRequiredParents(['Address'])
            ->save();

        $this->assertSame($address->id, $author->address_id);
        // Excepting Address means no extra Address/City/Country chain is built
        // beyond the single Address (with its own required City+Country) above.
        $this->assertSame(1, AddressFactory::query()->count());
        $this->assertSame($cityCountBefore, CityFactory::query()->count());
    }

    /**
     * Side-effect free: composing required parents writes NOTHING to the
     * database until the root factory is persisted. `->build()` stays purely
     * in-memory (this is the core `with*()` contract — eagerly persisting at
     * chain time would break atomicity and leak rows).
     */
    public function testWithRequiredParentsIsSideEffectFreeUntilPersist(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents()
            ->build();

        $this->assertTrue($author->isNew(), 'build() must not persist.');
        $this->assertSame(0, AddressFactory::query()->count(), 'No Address row written by build().');
        $this->assertSame(0, CountryFactory::query()->count(), 'No Country row written by build().');
    }

    /**
     * Default (no `recycle()`): each produced row in a counted batch gets its
     * own full required parent chain — correct for independent fixtures.
     */
    public function testCountedBatchBuildsADistinctChainPerRowByDefault(): void
    {
        $authors = RequiredParentsAuthorFactory::new()
            ->count(3)
            ->withRequiredParents()
            ->saveMany();

        $this->assertCount(3, $authors);
        $ids = array_map(fn ($a): int => $a->address_id, $authors);
        $this->assertCount(3, array_unique($ids), 'A distinct Address per row.');
        $this->assertSame(3, AddressFactory::query()->count());
        $this->assertSame(3, CityFactory::query()->count());
        $this->assertSame(3, CountryFactory::query()->count());
    }

    /**
     * `withRequiredParents()` composes cleanly with the established
     * `recycle()` pattern: build a shared parent yourself and every produced
     * row's required chain reuses it. This is the supported way to dedup a
     * batch — the method never persists on its own, so it stays atomic.
     */
    public function testComposesWithRecycleToShareOneParentAcrossBatch(): void
    {
        $country = CountryFactory::new()->save();

        $authors = RequiredParentsAuthorFactory::new()
            ->count(5)
            ->withRequiredParents()
            ->recycle($country)
            ->saveMany();

        $this->assertCount(5, $authors);
        $this->assertSame(
            1,
            CountryFactory::query()->count(),
            'Every row\'s required chain reuses the single recycled Country.',
        );
        // Addresses/Cities still fan out per row (only Country was recycled).
        $this->assertSame(5, AddressFactory::query()->count());
    }

    /**
     * An explicit `->with('Address', $entity)` already satisfies the alias, so
     * `withRequiredParents()` must NOT double-compose it — the explicit entity
     * wins and only one Address exists.
     */
    public function testExplicitWithIsNotDoubleComposed(): void
    {
        $address = AddressFactory::new()->save();
        $cityCountBefore = CityFactory::query()->count();

        $author = RequiredParentsAuthorFactory::new()
            ->with('Address', $address)
            ->withRequiredParents()
            ->save();

        $this->assertSame($address->id, $author->address_id, 'Explicit Address wins.');
        $this->assertSame(1, AddressFactory::query()->count(), 'No second Address composed.');
        $this->assertSame(
            $cityCountBefore,
            CityFactory::query()->count(),
            'Explicit Address already had its chain — none re-built.',
        );
    }

    /**
     * A required parent composed as a bare FACTORY (explicitly or via a
     * configure() default) must be recursively enriched, not skipped: its own
     * required grandchildren (here `Address.city_id` → City → Country) have to
     * be satisfied or the save fails on the NOT NULL the API is meant to
     * prevent. The caller's Address factory is kept (not replaced).
     */
    public function testComposedParentFactoryIsRecursivelyEnriched(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->with('Address', AddressFactory::new()->setField('street', 'Pinned Street'))
            ->withRequiredParents()
            ->save();

        $this->assertNotNull($author->address_id, 'Address composed.');
        $address = AddressFactory::query()->firstOrFail();
        $this->assertSame('Pinned Street', $address->get('street'), "Caller's Address factory kept.");
        $this->assertNotNull(
            $address->get('city_id'),
            'Grandchild Address.city_id satisfied — composed factory was enriched, not skipped.',
        );
        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame(1, CountryFactory::query()->count());
    }

    /**
     * Order independence with NO orphan: an explicit `->with()` placed AFTER
     * `withRequiredParents()` wins, and — because composition is side-effect
     * free until persist — the auto chain leaves no stray pre-built Address
     * row behind. Exactly one Address (the explicit one) exists.
     */
    public function testExplicitWithAfterWithRequiredParentsLeavesNoOrphan(): void
    {
        $address = AddressFactory::new()->save();

        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents()
            ->with('Address', $address)
            ->save();

        $this->assertSame($address->id, $author->address_id, 'Explicit Address wins.');
        $this->assertSame(
            1,
            AddressFactory::query()->count(),
            'No orphaned auto-built Address — composition is side-effect free.',
        );
    }

    /**
     * Composes cleanly with `autoSkipComposeOnExplicitForeignKey`: pinning the
     * FK at the call site auto-skips the composed parent, so the explicit
     * value survives and no throw-away parent row is created. Uses
     * `TestApp\Test\Factory\AuthorFactory` (no configure() defaults) so the
     * only composition is from `withRequiredParents()`.
     */
    public function testInteractionWithAutoSkipComposeOnExplicitForeignKey(): void
    {
        $this->assertTrue((bool)Configure::read('FixtureFactories.autoSkipComposeOnExplicitForeignKey', true));
        $address = AddressFactory::new()->save();
        $addressCountBefore = AddressFactory::query()->count();

        $author = AuthorFactory::new(['address_id' => $address->id])
            ->withRequiredParents()
            ->save();

        $this->assertSame($address->id, $author->address_id, 'Pinned FK survives.');
        $this->assertSame(
            $addressCountBefore,
            AddressFactory::query()->count(),
            'No throw-away Address row created when the FK is pinned.',
        );
    }

    /**
     * Consistency with the opt-out flag: when
     * `autoSkipComposeOnExplicitForeignKey` is OFF, the library's legacy
     * contract is "composed parent overrides the explicit FK". So
     * `withRequiredParents()` must still compose the parent (a fresh Address
     * is created and wins), matching the rest of the library rather than
     * silently dropping the alias.
     */
    public function testPinnedForeignKeyStillComposesWhenAutoSkipDisabled(): void
    {
        Configure::write('FixtureFactories.autoSkipComposeOnExplicitForeignKey', false);
        $address = AddressFactory::new()->save();
        $addressCountBefore = AddressFactory::query()->count();

        $author = AuthorFactory::new(['address_id' => $address->id])
            ->withRequiredParents()
            ->save();

        $this->assertNotNull($author->address_id);
        $this->assertSame(
            $addressCountBefore + 1,
            AddressFactory::query()->count(),
            'Opt-out mode: the composed parent is created (legacy override behavior).',
        );
    }

    /**
     * A non-null FK on a `Factory::new($entity)` payload that is a HIDDEN
     * entity property must still be detected as pinned (probed via has()/get(),
     * not toArray() which drops hidden fields), so no throw-away parent is
     * composed over it.
     */
    public function testHiddenEntityForeignKeyIsTreatedAsPinned(): void
    {
        $address = AddressFactory::new()->save();
        $author = AuthorFactory::new()->getTable()->newEntity(
            ['name' => 'Hidden FK', 'address_id' => $address->id],
            ['accessibleFields' => ['*' => true]],
        );
        $author->setHidden(['address_id'], true);
        $addressCountBefore = AddressFactory::query()->count();

        $built = AuthorFactory::new($author)
            ->withRequiredParents()
            ->save();

        $this->assertSame($address->id, $built->address_id, 'Hidden entity FK survives.');
        $this->assertSame(
            $addressCountBefore,
            AddressFactory::query()->count(),
            'No throw-away Address for a hidden but set FK.',
        );
    }

    /**
     * A FK pinned for *every* produced row via `sequenceField()` is explicit
     * caller state and must be honored: the parent is not auto-composed and
     * the sequenced value survives ("explicit FK wins" for counted builds).
     */
    public function testSequencePinnedForeignKeyIsHonored(): void
    {
        $a1 = AddressFactory::new()->save();
        $a2 = AddressFactory::new()->save();
        $addressCountBefore = AddressFactory::query()->count();

        $authors = AuthorFactory::new()
            ->count(2)
            ->sequenceField('address_id', $a1->id, $a2->id)
            ->withRequiredParents()
            ->saveMany();

        $this->assertSame([$a1->id, $a2->id], [$authors[0]->address_id, $authors[1]->address_id]);
        $this->assertSame(
            $addressCountBefore,
            AddressFactory::query()->count(),
            'No throw-away Address created when the FK is sequence-pinned for every row.',
        );
    }

    /**
     * Cycle detection is path-scoped, not global: two distinct sibling
     * branches that each reach the SAME physical table (Address and
     * BusinessAddress both → addresses) is a DAG, not a cycle, and must NOT
     * raise the cycle exception.
     */
    public function testSiblingBranchesToSameTableAreNotAFalseCycle(): void
    {
        $author = RequiredParentsOverrideAuthorFactory::new()
            ->withRequiredParents()
            ->save();

        $this->assertNotNull($author->address_id);
        $this->assertNotNull($author->business_address_id);
    }

    /**
     * A non-null belongsTo FK supplied via ENTITY instantiation
     * (`Factory::new($entity)`) already satisfies that parent: the resolver
     * must treat it as pinned and not auto-compose a throw-away parent that
     * would overwrite the caller-provided value.
     */
    public function testEntityInstantiatedForeignKeyIsTreatedAsPinned(): void
    {
        $address = AddressFactory::new()->save();
        $author = AuthorFactory::new()->getTable()->newEntity(
            ['name' => 'Pinned', 'address_id' => $address->id],
            ['accessibleFields' => ['*' => true]],
        );
        $addressCountBefore = AddressFactory::query()->count();

        $built = AuthorFactory::new($author)
            ->withRequiredParents()
            ->save();

        $this->assertSame($address->id, $built->address_id, 'Entity-supplied FK survives.');
        $this->assertSame(
            $addressCountBefore,
            AddressFactory::query()->count(),
            'No throw-away Address created for an entity-pinned FK.',
        );
    }

    /**
     * The hook is additive: it opts the (otherwise nullable, not
     * auto-resolved) BusinessAddress into composition while the ordinary
     * required Address still comes from automatic detection. This is the
     * supported, non-guessing escape hatch for composite /
     * custom-join associations automatic detection refuses to build.
     */
    public function testOverrideHookOptsInExtraAssociation(): void
    {
        $author = RequiredParentsOverrideAuthorFactory::new()
            ->withRequiredParents()
            ->save();

        $this->assertNotNull($author->address_id, 'Address still composed.');
        $this->assertNotNull(
            $author->business_address_id,
            'Hook opted BusinessAddress in even though its FK is nullable.',
        );
    }

    /**
     * A composite-key belongsTo is never auto-resolved, but the additive hook
     * can opt it in explicitly. Save-time composition must populate every FK
     * component from the built parent and persist the parent's own required
     * chain too.
     */
    public function testOverrideHookOptsInCompositeKeyAssociation(): void
    {
        $row = RequiredParentsCompositeOptInTableWithoutModelFactory::new()
            ->withRequiredParents()
            ->save();

        $city = CityFactory::query()->firstOrFail();

        $this->assertNotNull($row->id);
        $this->assertSame($city->id, $row->get('foreign_key'));
        $this->assertSame($city->country_id, $row->get('country_id'));
        $this->assertSame(1, CityFactory::query()->count(), 'Composite opt-in must compose the parent City.');
        $this->assertSame(
            1,
            CountryFactory::query()->count(),
            'The opted-in composite parent must still satisfy its own required Country chain.',
        );
    }

    /**
     * Symmetric counterpart to the additive `requiredParentAssociations()`
     * hook: `excludedRequiredParentAssociations()` lets a factory class
     * permanently drop an auto-detected NOT NULL belongsTo from
     * `withRequiredParents()`, for FKs satisfied another way (DB default,
     * trigger, a custom join the caller always supplies). The per-call
     * `$except` still works for one-off exclusions; this is the
     * factory-class-level equivalent so call sites stay clean.
     */
    public function testExcludeHookDropsAutoDetectedRequiredParent(): void
    {
        $author = RequiredParentsExcludeAuthorFactory::new()
            ->withRequiredParents()
            ->build();

        $this->assertEmpty(
            $author->address,
            'excludedRequiredParentAssociations() must drop the alias from auto-detection.',
        );
        $this->assertNull(
            $author->address_id,
            'No Address composed -> the (still NOT NULL) FK stays unset in-memory.',
        );
    }

    /**
     * A `foreignKey => false` custom-condition belongsTo (the classic uuid
     * join — a PR #85 brittle case) must NEVER be auto-resolved, even though
     * it is technically a belongsTo. Only the override hook can opt it in.
     */
    public function testForeignKeyFalseCustomJoinIsNotAutoResolved(): void
    {
        $factory = RequiredParentsAuthorFactory::new();
        $factory->getTable()->belongsTo('GhostCountry', [
            'className' => 'Countries',
            'foreignKey' => false,
            'conditions' => ['Authors.name = GhostCountry.name'],
        ]);

        // This test exercises withRequiredParents()'s handling of a
        // foreignKey => false join, not the FK-in-definition() detector. The
        // contrived `Authors.name = GhostCountry.name` join makes `name`
        // (a real definition() data field) double as the recovered join
        // column, so strictDefinition would correctly — but irrelevantly —
        // flag it here. Isolate from the detector.
        $strict = Configure::read('FixtureFactories.strictDefinition');
        Configure::write('FixtureFactories.strictDefinition', false);
        try {
            $author = $factory
                ->withRequiredParents()
                ->save();
        } finally {
            Configure::write('FixtureFactories.strictDefinition', $strict);
        }

        $this->assertNotNull($author->id, 'Build still succeeds.');
        // Only the legitimate required chain built a Country (via City);
        // the foreignKey=>false GhostCountry must NOT have added another.
        $this->assertSame(
            1,
            CountryFactory::query()->count(),
            'foreignKey => false belongsTo must not be auto-resolved.',
        );
    }

    /**
     * Persisting an explicitly-composed `foreignKey => false` belongsTo must
     * succeed: the parent is built / saved independently and is NOT cascaded
     * via the (broken upstream) `BelongsTo::saveAssociated` path. Cake's
     * `saveAssociated` casts `(array)false` → `[false]` and produces an empty
     * field for `patch()` — "Cannot set an empty field". A custom-condition
     * join has no FK to populate, so we never set the property for cascade.
     */
    public function testPersistingForeignKeyFalseComposedParentSucceeds(): void
    {
        $countryCountBefore = CountryFactory::query()->count();
        $factory = RequiredParentsAuthorFactory::new();
        $factory->getTable()->belongsTo('GhostCountry', [
            'className' => 'Countries',
            'foreignKey' => false,
            'conditions' => ['Authors.name = GhostCountry.name'],
        ]);

        // strictDefinition would (correctly but irrelevantly) flag `name` as
        // the recovered join column; this test exercises foreignKey => false
        // persistence, not the detector.
        $strict = Configure::read('FixtureFactories.strictDefinition');
        Configure::write('FixtureFactories.strictDefinition', false);
        try {
            $author = $factory
                ->with('GhostCountry', CountryFactory::new())
                ->withRequiredParents()
                ->save();
        } finally {
            Configure::write('FixtureFactories.strictDefinition', $strict);
        }

        $this->assertNotNull($author->id, 'Author persisted.');
        $this->assertSame(
            $countryCountBefore + 2,
            CountryFactory::query()->count(),
            'Both the chain Country (via City) and the foreignKey=>false GhostCountry parent persisted.',
        );
    }

    /**
     * Same fix exercised through the documented additive-hook entry point:
     * a factory that opts a `foreignKey => false` association in via
     * `requiredParentAssociations()` must compose AND persist it cleanly.
     */
    public function testAdditiveHookComposesForeignKeyFalseAssociation(): void
    {
        $countryCountBefore = CountryFactory::query()->count();
        $factory = RequiredParentsForeignKeyFalseOptInFactory::new();
        $factory->getTable()->belongsTo('GhostCountry', [
            'className' => 'Countries',
            'foreignKey' => false,
            'conditions' => ['Authors.name = GhostCountry.name'],
        ]);

        $strict = Configure::read('FixtureFactories.strictDefinition');
        Configure::write('FixtureFactories.strictDefinition', false);
        try {
            $author = $factory->withRequiredParents()->save();
        } finally {
            Configure::write('FixtureFactories.strictDefinition', $strict);
        }

        $this->assertNotNull($author->id);
        $this->assertSame(
            $countryCountBefore + 2,
            CountryFactory::query()->count(),
            'Additive-hook opt-in of a foreignKey=>false belongsTo composes the parent.',
        );
    }

    /**
     * Recycle dedup within a single recursive chain: a SINGLE build composes
     * exactly one of each required ancestor along its chain (Author -> Address
     * -> City -> Country = one row each), never fanning out.
     *
     * Note the well-defined boundary: two *distinct-alias* belongsTo that both
     * target the same table (Address + BusinessAddress) each build their own
     * independent chain — that is per-alias intent, not a shared ancestor.
     * Cross-row dedup for a counted batch is covered separately.
     */
    public function testSingleBuildComposesOneOfEachAlongTheChain(): void
    {
        $author = RequiredParentsOverrideAuthorFactory::new()
            ->withRequiredParents()
            ->save();

        $this->assertNotNull($author->address_id);
        $this->assertNotNull($author->business_address_id);
        // Two distinct aliases (Address, BusinessAddress) → two independent
        // chains; each chain itself is built exactly once (no fan-out within).
        $this->assertNotSame(
            $author->address_id,
            $author->business_address_id,
            'Distinct aliases keep their own parent — per-alias intent preserved.',
        );
        $this->assertSame(2, AddressFactory::query()->count(), 'One Address per distinct alias.');
        $this->assertSame(2, CityFactory::query()->count(), 'One City per chain — no fan-out within a chain.');
        $this->assertSame(2, CountryFactory::query()->count(), 'One Country per chain.');
    }

    /**
     * A *null* value pinned on a required FK does NOT satisfy a NOT NULL
     * constraint, so the parent must still be composed (matches the non-null
     * semantics of autoSkipComposeOnExplicitForeignKey). Without this, the
     * build would fail on the NOT NULL constraint.
     */
    public function testNullPinnedForeignKeyStillComposesParent(): void
    {
        $author = RequiredParentsAuthorFactory::new(['address_id' => null])
            ->withRequiredParents()
            ->save();

        $this->assertNotNull(
            $author->address_id,
            'A null-pinned required FK must still get a composed parent.',
        );
        $this->assertSame(1, AddressFactory::query()->count());
    }

    /**
     * A required (NOT NULL) belongsTo cycle is mathematically unsatisfiable by
     * auto-composition. It must fail loudly with an actionable message — not
     * recurse forever, and not silently produce a factory that later dies on a
     * confusing NOT NULL violation. (`SelfRef` reuses the NOT NULL `address_id`
     * column but targets Authors itself — an A -> A cycle.)
     */
    public function testCyclicRequiredParentGraphThrowsActionableException(): void
    {
        $factory = RequiredParentsAuthorFactory::new();
        $factory->getTable()->belongsTo('SelfRef', [
            'className' => 'Authors',
            'foreignKey' => 'address_id',
        ]);

        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('NOT NULL) belongsTo cycle');

        $factory->withRequiredParents();
    }

    /**
     * The actionable escape hatch works: pinning the cyclic FK at the call
     * site and excluding the cyclic alias via `$except` breaks the cycle so
     * the row becomes persistable.
     */
    public function testExceptBreaksAnOtherwiseUnsatisfiableCycle(): void
    {
        $address = AddressFactory::new()->save();

        $factory = RequiredParentsAuthorFactory::new(['address_id' => $address->id]);
        $factory->getTable()->belongsTo('SelfRef', [
            'className' => 'Authors',
            'foreignKey' => 'address_id',
        ]);

        // SelfRef + Address both ride address_id; pin it and except both.
        $author = $factory
            ->withRequiredParents(['SelfRef', 'Address'])
            ->save();

        $this->assertSame($address->id, $author->address_id);
    }

    /**
     * Honor an explicitly-composed terminating parent that breaks an
     * otherwise self-referential required cycle: when `->with('SelfRef',
     * $entity)` supplies the cycle's terminating row, `withRequiredParents()`
     * must NOT throw the cycle exception (the explicit entity wins). The
     * cycle check runs only for aliases it would actually recurse into.
     */
    public function testExplicitEntityBreaksCycleInsteadOfThrowing(): void
    {
        $terminator = AuthorFactory::new()->withRequiredParents()->save();

        $factory = RequiredParentsAuthorFactory::new();
        $factory->getTable()->belongsTo('SelfRef', [
            'className' => 'Authors',
            'foreignKey' => 'address_id',
        ]);

        // SelfRef satisfied by a concrete entity (cycle broken). No exception
        // must be raised while composing the chain.
        $enriched = $factory
            ->with('SelfRef', $terminator)
            ->withRequiredParents(['Address']);

        $this->assertInstanceOf(RequiredParentsAuthorFactory::class, $enriched);
    }

    /**
     * A shared-primary-key 1:1 belongsTo (the FK column IS the table's primary
     * key, e.g. `child.id` → `parent.id`) is a legitimate single scalar NOT
     * NULL belongsTo and MUST be auto-resolved — it must not be excluded just
     * because the column is also the primary key.
     */
    public function testSharedPrimaryKeyBelongsToIsResolved(): void
    {
        $factory = RequiredParentsAuthorFactory::new();
        // Authors.id is the PK; declare a belongsTo whose FK is that PK.
        $factory->getTable()->belongsTo('PkParent', [
            'className' => 'Addresses',
            'foreignKey' => 'id',
        ]);

        $resolver = new ReflectionMethod(
            BaseFactory::class,
            'resolveRequiredParentAliases',
        );

        $aliases = $resolver->invoke($factory, []);

        $this->assertContains(
            'PkParent',
            $aliases,
            'A shared-primary-key belongsTo must not be excluded from auto-resolution.',
        );
    }

    /**
     * A factory whose root table has no required (NOT NULL single-scalar-FK)
     * belongsTo is a clean no-op — Countries has no belongsTo at all.
     */
    public function testNoRequiredParentsIsNoOp(): void
    {
        $country = CountryFactory::new()
            ->withRequiredParents()
            ->save();

        $this->assertNotNull($country->id);
        $this->assertSame(1, CountryFactory::query()->count());
    }

    /**
     * `maxDepth: 1` composes only the root's *direct* required parents — the
     * grandparent chain is intentionally not recursed into. The composed row
     * may then be un-persistable (its own NOT NULL FK is unsatisfied); that is
     * the caller's responsibility, exactly like `$except`.
     */
    public function testMaxDepthOneComposesOnlyDirectParentNotGrandparent(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents(maxDepth: 1)
            ->build();

        $this->assertNotEmpty(
            $author->address,
            'maxDepth:1 still composes the direct required parent Address.',
        );
        $this->assertEmpty(
            $author->address->city,
            'maxDepth:1 must NOT recurse into the grandparent City.',
        );
    }

    /**
     * `maxDepth: 2` composes the root's parents and their parents, but stops
     * before the third level. Here: Address + City, but not Country.
     */
    public function testMaxDepthTwoComposesTwoLevels(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents(maxDepth: 2)
            ->build();

        $this->assertNotEmpty($author->address, 'Level 1 (Address) composed.');
        $this->assertNotEmpty($author->address->city, 'Level 2 (City) composed.');
        $this->assertEmpty(
            $author->address->city->country,
            'maxDepth:2 must NOT recurse into the third level (Country).',
        );
    }

    /**
     * Explicit `maxDepth: null` is the unbounded default: the whole NOT NULL
     * chain is composed, identical to calling `withRequiredParents()`.
     */
    public function testMaxDepthNullComposesWholeChain(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents(maxDepth: null)
            ->build();

        $this->assertNotEmpty($author->address, 'Address composed.');
        $this->assertNotEmpty($author->address->city, 'City composed.');
        $this->assertNotEmpty(
            $author->address->city->country,
            'maxDepth:null composes the whole chain down to Country.',
        );
    }

    /**
     * `maxDepth: 0` (or negative) is a confusing footgun — "compose zero
     * required parents" is just not calling the method. Reject it loudly at
     * call time instead of silently composing nothing.
     */
    public function testMaxDepthZeroThrowsInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RequiredParentsAuthorFactory::new()->withRequiredParents(maxDepth: 0);
    }

    /**
     * A negative cap is equally nonsensical and rejected the same way.
     */
    public function testMaxDepthNegativeThrowsInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RequiredParentsAuthorFactory::new()->withRequiredParents(maxDepth: -1);
    }

    /**
     * The documented `maxDepth` trap: capping below the real required depth
     * produces a row whose own NOT NULL FK is unsatisfied, so `->save()`
     * fails. This is the caller's responsibility (same contract as `$except`),
     * and — being side-effect free — nothing leaks: no Author/Address row is
     * written when the persist aborts.
     */
    public function testMaxDepthBelowRequiredDepthProducesUnpersistableRow(): void
    {
        $threw = false;
        try {
            RequiredParentsAuthorFactory::new()
                ->withRequiredParents(maxDepth: 1)
                ->save();
        } catch (Throwable $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            'maxDepth:1 leaves Address.city_id (NOT NULL) unsatisfied, so save() must fail.',
        );
        $this->assertSame(0, AddressFactory::query()->count(), 'No Address leaked.');
        $this->assertSame(
            0,
            RequiredParentsAuthorFactory::query()->count(),
            'No Author leaked — composition is atomic.',
        );
    }

    /**
     * Opt-in `strict: true`: when `maxDepth` actually truncates the required
     * chain — a composed boundary parent still has its own unsatisfied
     * required belongsTo — fail loudly at call time with an actionable
     * message instead of silently producing an un-persistable row.
     */
    public function testStrictThrowsWhenMaxDepthDropsANeededParent(): void
    {
        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('maxDepth');

        RequiredParentsAuthorFactory::new()
            ->withRequiredParents(maxDepth: 1, strict: true);
    }

    /**
     * `strict: true` is a no-op when the cap is deep enough to satisfy the
     * whole required chain — no exception, full chain composed.
     */
    public function testStrictDoesNotThrowWhenCapCoversTheChain(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents(maxDepth: 3, strict: true)
            ->build();

        $this->assertNotEmpty($author->address->city->country);
    }

    /**
     * `strict: true` with no cap (full chain) never throws — there is no
     * truncation to be strict about.
     */
    public function testStrictWithoutMaxDepthIsHarmless(): void
    {
        $author = RequiredParentsAuthorFactory::new()
            ->withRequiredParents(strict: true)
            ->save();

        $this->assertNotNull($author->id);
    }

    /**
     * `recycle()` cannot rescue a capped branch: recycle only substitutes
     * *composed* belongsTo branches, and `maxDepth` stops the recycled
     * table's branch from being composed at all. So `maxDepth` + a recycle
     * for a beyond-cap table is genuinely un-persistable, and `strict`
     * correctly reports it rather than being silenced by the recycle.
     */
    public function testStrictStillThrowsWhenRecycleCannotReachACappedBranch(): void
    {
        $country = CountryFactory::new()->save();

        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('maxDepth');

        RequiredParentsAuthorFactory::new()
            ->recycle($country)
            ->withRequiredParents(maxDepth: 2, strict: true);
    }

    /**
     * Recycling a *mid-chain* required parent (not just the leaf) must reuse
     * it, not silently rebuild it. `withRequiredParents()` auto-composes the
     * chain; that auto-composition must not count as user `with()` intent and
     * defeat recycle substitution for an intermediate node.
     */
    public function testRecyclingAMidChainParentReusesItAcrossBatch(): void
    {
        // A persisted City (with its own required Country) to share.
        $city = CityFactory::new()->withRequiredParents()->save();
        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame(1, CountryFactory::query()->count());

        $authors = RequiredParentsAuthorFactory::new()
            ->count(4)
            ->withRequiredParents()
            ->recycle($city)
            ->saveMany();

        $this->assertCount(4, $authors);
        $this->assertSame(
            1,
            CityFactory::query()->count(),
            'The recycled mid-chain City must be reused by every row, not rebuilt.',
        );
        $this->assertSame(
            1,
            CountryFactory::query()->count(),
            'No extra Country built behind a duplicated City.',
        );
        // Addresses still fan out (Address was not recycled).
        $this->assertSame(4, AddressFactory::query()->count());
    }

    /**
     * The canonical downstream-consumer form: a factory whose `configure()`
     * encodes `withRequiredParents()` as its default. Calling
     * `Factory::new()->save()` (with no explicit chain on the call site)
     * must compose the required parents AND propagate their ids into the
     * child's foreign-key columns — same contract as calling
     * `withRequiredParents()` externally on the new() result.
     *
     * Regression guard: a previous change demoted auto-resolved parents to
     * the configure-defaults bucket so recycle() can substitute them; that
     * must not lose foreign-key column propagation on the configure()-time
     * path.
     *
     * @return void
     */
    public function testConfigureTimeWithRequiredParentsPropagatesForeignKey(): void
    {
        $author = RequiredParentsAuthorConfiguredFactory::new()->save();

        $this->assertNotNull($author->id);
        $this->assertNotNull(
            $author->address_id,
            'Required Address must be composed AND its id propagated into address_id, '
            . 'whether withRequiredParents() is called externally or from configure().',
        );
        // The composed Address really persisted (id reachable via the FK).
        $address = TableRegistry::getTableLocator()->get('Addresses')
            ->find()->where(['id' => $author->address_id])->first();
        $this->assertNotNull($address, 'Composed Address must be persisted and matchable by the propagated FK.');
    }

    public function testInstantiationPinIsNotTrustedWhenSequenceMayOverrideIt(): void
    {
        // Factory::new(['address_id' => $known->id]) pins the FK via
        // instantiation. But sequenceField('address_id', [null, null])
        // overrides it to null on every row at build time (sequence runs
        // AFTER instantiation in compileEntity). getPinnedFields() used to
        // treat the instantiation pin as authoritative even when sequence
        // touched the same field — withRequiredParents() then skipped the
        // Address composition, sequence set the FK to null, and saveMany()
        // failed with a NOT NULL constraint violation.
        //
        // Fix contract: when sequence/sequenceField touches a field AND
        // does not itself pin it to non-null on every row, the
        // instantiation-side pin must NOT be trusted — withRequiredParents()
        // composes the parent so the FK is supplied through the normal
        // belongsTo cascade after sequence runs.
        $known = AddressFactory::new()->save();
        $addressesBefore = AddressFactory::query()->count();

        $authors = RequiredParentsAuthorFactory::new(['address_id' => $known->id])
            ->sequenceField('address_id', null, null)
            ->withRequiredParents()
            ->count(2)
            ->saveMany();

        $this->assertCount(2, $authors, 'saveMany must not throw on NOT NULL violation.');
        foreach ($authors as $author) {
            $this->assertNotNull(
                $author->address_id,
                'Each saved row must have a non-null address_id (composed via withRequiredParents()).',
            );
        }
        $this->assertGreaterThan(
            $addressesBefore,
            AddressFactory::query()->count(),
            'New Address rows must be composed when sequenceField nulls out the instantiation-pinned FK.',
        );
    }

    public function testEntityInstantiationPinIsNotTrustedWhenSequenceMayOverrideIt(): void
    {
        // Same defect as testInstantiationPinIsNotTrustedWhenSequenceMayOverrideIt
        // but on the entity-instantiation path: Factory::new($entityWithFk)
        // populates the DataCompiler's $instantiationEntity, which the
        // pinned-FK check (BaseFactory::belongsToFkAlreadyPinned) probes
        // directly. If sequence/sequenceField touches that FK at build time
        // without pinning it non-null on every row, the entity-side pin
        // must NOT be trusted either — same contract as the array path.
        //
        // Entity instantiation is single-row only (the wrapper API does not
        // support count() > 1 on an injected entity), so this exercises the
        // count = 1 + all-null cycle shape, which is enough to surface
        // the bug: sequence overrides the entity-side FK pin to null and
        // the save fails with NOT NULL.
        $known = AddressFactory::new()->save();
        $authorEntity = RequiredParentsAuthorFactory::new(['address_id' => $known->id])->build();

        $addressesBefore = AddressFactory::query()->count();

        $author = RequiredParentsAuthorFactory::new($authorEntity)
            ->state(['name' => 'Test Author']) // keep name dirty across the re-wrap
            ->sequenceField('address_id', null)
            ->withRequiredParents()
            ->save();

        $this->assertNotNull(
            $author->address_id,
            'Saved row must have a non-null address_id (composed via withRequiredParents()).',
        );
        $this->assertGreaterThan(
            $addressesBefore,
            AddressFactory::query()->count(),
            'A new Address row must be composed when sequenceField nulls out the entity-pinned FK.',
        );
    }

    public function testHookOptedRequiredParentRespectsCallerPinnedForeignKey(): void
    {
        // RequiredParentsOverrideAuthorFactory opts BusinessAddress into
        // withRequiredParents() via the additive requiredParentAssociations()
        // hook. When the caller pins business_address_id explicitly, the
        // hook-opted alias must NOT silently override the pinned value
        // by composing a fresh BusinessAddress — that contradicts the
        // documented "pinned FK wins" contract that the auto-detected
        // aliases already honor.
        //
        // Pin address_id too (auto-detected NOT NULL required parent) so the
        // address-row count below is unambiguous — any extra Address row would
        // necessarily be the BusinessAddress composition we're guarding against.
        $primary = AddressFactory::new()->save();
        $secondary = AddressFactory::new()->save();
        $addressesBefore = AddressFactory::query()->count();

        $author = RequiredParentsOverrideAuthorFactory::new([
            'address_id' => $primary->id,
            'business_address_id' => $secondary->id,
        ])
            ->withRequiredParents()
            ->save();

        $this->assertSame(
            $secondary->id,
            $author->business_address_id,
            'A caller-pinned FK on a hook-opted required parent must survive — '
            . 'withRequiredParents() must not compose a fresh BusinessAddress over it.',
        );
        $this->assertSame(
            $primary->id,
            $author->address_id,
            'A caller-pinned FK on an auto-detected required parent must also survive '
            . '(existing contract; asserted alongside to keep the regression scope clear).',
        );
        $this->assertSame(
            $addressesBefore,
            AddressFactory::query()->count(),
            'No fresh Address should be composed when both required-parent FKs are pinned.',
        );
    }
}
