<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\TestSuite\TableAssertionsTrait;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use stdClass;
use TestApp\Model\Entity\Country;

/**
 * Tests for `TableAssertionsTrait` — expressive assertions composed over
 * `Factory::query()`. Stays on the v2 side of the design line: no factory
 * read-surface additions, just a test-suite trait.
 *
 * Failure-message tests use try/catch (rather than `expectException`) because
 * the trait methods themselves are named `assertX()`, which trips the
 * "no assert after expectException" CS rule when paired.
 */
class TableAssertionsTraitTest extends TestCase
{
    use TableAssertionsTrait;
    use TruncateDirtyTables;

    public function testAssertTableEmptyPassesOnFreshTable(): void
    {
        $this->assertTableEmpty(CountryFactory::class);
    }

    public function testAssertTableEmptyFailsWhenRowsExist(): void
    {
        CountryFactory::new()->save();

        $message = $this->captureFailureMessage(
            fn () => $this->assertTableEmpty(CountryFactory::class),
        );
        $this->assertMatchesRegularExpression('/CountryFactory.*empty.*found 1/i', $message);
    }

    public function testAssertTableCountMatchesExactRowCount(): void
    {
        CountryFactory::new()->count(3)->saveMany();

        $this->assertTableCount(CountryFactory::class, 3);
    }

    public function testAssertTableCountFailsOnMismatch(): void
    {
        CountryFactory::new()->count(3)->saveMany();

        $message = $this->captureFailureMessage(
            fn () => $this->assertTableCount(CountryFactory::class, 5),
        );
        $this->assertMatchesRegularExpression('/Expected 5.*found 3/i', $message);
    }

    public function testAssertTableCountWithCriteriaFiltersBeforeCounting(): void
    {
        CountryFactory::new(['name' => 'Kenya'])->save();
        CountryFactory::new(['name' => 'France'])->save();
        CountryFactory::new(['name' => 'France'])->save();

        $this->assertTableCount(CountryFactory::class, 2, ['name' => 'France']);
        $this->assertTableCount(CountryFactory::class, 1, ['name' => 'Kenya']);
        $this->assertTableCount(CountryFactory::class, 0, ['name' => 'Atlantis']);
    }

    public function testAssertTableHasPassesWhenAtLeastOneRowMatches(): void
    {
        CountryFactory::new(['name' => 'Kenya'])->save();
        CountryFactory::new(['name' => 'Atlantis'])->save();

        $this->assertTableHas(CountryFactory::class, ['name' => 'Kenya']);
    }

    public function testAssertTableHasFailsWhenNoRowMatches(): void
    {
        CountryFactory::new(['name' => 'Real Country'])->save();

        $message = $this->captureFailureMessage(
            fn () => $this->assertTableHas(CountryFactory::class, ['name' => 'Atlantis']),
        );
        $this->assertMatchesRegularExpression('/at least one.*name.*Atlantis/i', $message);
    }

    public function testAssertTableMissingPassesWhenNoRowMatches(): void
    {
        CountryFactory::new(['name' => 'Kenya'])->save();

        $this->assertTableMissing(CountryFactory::class, ['name' => 'Atlantis']);
    }

    public function testAssertTableMissingFailsWhenRowExists(): void
    {
        CountryFactory::new(['name' => 'Spam'])->save();

        $message = $this->captureFailureMessage(
            fn () => $this->assertTableMissing(CountryFactory::class, ['name' => 'Spam']),
        );
        $this->assertMatchesRegularExpression('/no rows.*name.*Spam/i', $message);
    }

    public function testAssertEntityExistsPassesForSavedEntity(): void
    {
        $country = CountryFactory::new()->save();

        $this->assertEntityExists($country);
    }

    public function testAssertEntityExistsFailsForUnsavedEntity(): void
    {
        $country = CountryFactory::new(['name' => 'Ghost'])->build();

        $message = $this->captureFailureMessage(
            fn () => $this->assertEntityExists($country),
        );
        $this->assertMatchesRegularExpression('/to exist .* but it does not/i', $message);
    }

    public function testAssertEntityMissingPassesForDeletedEntity(): void
    {
        $country = CountryFactory::new()->save();
        CountryFactory::table()->deleteAll(['id' => $country->id]);

        $this->assertEntityMissing($country);
    }

    public function testAssertEntityMissingFailsWhenStillPresent(): void
    {
        $country = CountryFactory::new()->save();

        $message = $this->captureFailureMessage(
            fn () => $this->assertEntityMissing($country),
        );
        $this->assertMatchesRegularExpression('/missing.*still exists/i', $message);
    }

    public function testAssertEntityMissingRejectsNeverPersistedEntity(): void
    {
        // A built-but-not-saved entity has a null primary key. The previous
        // implementation short-circuited to false on null PK and silently
        // passed — a green test that proved nothing. The guard must instead
        // throw so the caller knows they need to save (then delete) first.
        $country = CountryFactory::new(['name' => 'NeverSaved'])->build();

        // try/catch instead of expectException so the file-level CS rule
        // "no assert* after expect*" stays happy — the trait method is
        // named assertEntityMissing(), which the rule treats as an
        // assertion call.
        $caught = null;
        try {
            $this->assertEntityMissing($country);
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Expected InvalidArgumentException for never-persisted entity.');
        $this->assertMatchesRegularExpression('/it was never persisted/', $caught->getMessage());
    }

    public function testAssertEntityMissingRejectsNeverPersistedEntityWithPreassignedPrimaryKey(): void
    {
        // Application-assigned-PK case (UUID / string IDs): the entity carries
        // a non-null PK at build time but was never written. The null-PK guard
        // alone would let it slip past; the isNew() guard catches it.
        $country = new Country([
            'id' => 999_999,
            'name' => 'Pre-assigned but never saved',
        ]);
        $country->setSource('Countries');

        $caught = null;
        try {
            $this->assertEntityMissing($country);
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }
        $this->assertNotNull(
            $caught,
            'Expected InvalidArgumentException for an isNew() entity even with a pre-assigned PK.',
        );
        $this->assertMatchesRegularExpression('/it was never persisted/', $caught->getMessage());
    }

    public function testAssertTableHasComposesAcrossFactoryClasses(): void
    {
        CityFactory::new(['name' => 'Paris'])->save();
        CountryFactory::new(['name' => 'France'])->save();

        $this->assertTableHas(CityFactory::class, ['name' => 'Paris']);
        $this->assertTableHas(CountryFactory::class, ['name' => 'France']);
        $this->assertTableMissing(CityFactory::class, ['name' => 'France']);
        $this->assertTableMissing(CountryFactory::class, ['name' => 'Paris']);
    }

    public function testAssertEntityExistsAcceptsExplicitFactoryClassForScopedLookup(): void
    {
        // Per PR #72 review (Copilot): when multiple factories share a bare
        // alias on different connections, the entity-existence lookup must
        // honour an explicit $factoryClass so the user picks which table to
        // query against — not whichever factory variant most-recently won
        // the locator's `set($alias, ...)` race.
        $country = CountryFactory::new()->save();

        $this->assertEntityExists($country, CountryFactory::class);
        $this->assertEntityExists($country); // implicit-source path still works
    }

    public function testAssertEntityMissingAcceptsExplicitFactoryClass(): void
    {
        $country = CountryFactory::new()->save();
        CountryFactory::table()->deleteAll(['id' => $country->id]);

        $this->assertEntityMissing($country, CountryFactory::class);
    }

    public function testRenderScalarRendersArrayValuesInsteadOfTheWordArray(): void
    {
        // Per PR #72 review (Copilot): array criteria like
        // `['status IN' => ['draft', 'published']]` were rendered as
        // `{status IN: array}`. The failure message should include the
        // actual values.
        CountryFactory::new(['name' => 'France'])->save();

        $message = $this->captureFailureMessage(
            fn () => $this->assertTableHas(CountryFactory::class, ['name IN' => ['Atlantis', 'Mu']]),
        );
        $this->assertStringContainsString("'Atlantis'", $message);
        $this->assertStringContainsString("'Mu'", $message);
        $this->assertStringNotContainsString('array', $message);
    }

    /**
     * Run the closure, fail if it does NOT throw an `AssertionFailedError`,
     * otherwise return the failure message so the test can introspect it.
     */
    private function captureFailureMessage(callable $closure): string
    {
        try {
            $closure();
        } catch (AssertionFailedError $e) {
            return $e->getMessage();
        }
        $this->fail('Expected an AssertionFailedError but no exception was thrown.');
    }

    public function testAssertEntityExistsRejectsEntityWithoutSourceTable(): void
    {
        // resolveTableForEntity() throws a friendly InvalidArgumentException
        // when handed a bare entity with no source table set AND no factory
        // class argument. Without this guard the user would see Cake's raw
        // "alias is not allowed" error from the table locator. Regression pin.
        $entity = new Entity(['id' => 1]);
        $entity->setSource('');

        $caught = null;
        try {
            $this->assertEntityExists($entity);
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Expected InvalidArgumentException for entity with no source table.');
        $this->assertMatchesRegularExpression('/no source table/i', $caught->getMessage());
        $this->assertMatchesRegularExpression('/factoryClass/', $caught->getMessage());
    }

    public function testGuardFactoryClassRejectsNonBaseFactoryClassString(): void
    {
        // guardFactoryClass() throws when a non-BaseFactory class is passed
        // to any of the trait's assertion methods. Documented contract; pin
        // it so a refactor can't silently drop the friendly error in favour
        // of a confusing static-method-not-found further down the call chain.
        $caught = null;
        try {
            $this->assertTableEmpty(stdClass::class);
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'Expected InvalidArgumentException for non-BaseFactory class.');
        $this->assertMatchesRegularExpression('/must extend/i', $caught->getMessage());
    }
}
