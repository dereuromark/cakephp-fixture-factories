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
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\DataCompiler;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\CustomerFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

/**
 * Covers the auto-skip-composition feature: when a factory composes a
 * belongsTo parent in `configure()` and the caller explicitly provides that
 * association's foreign-key column at the call site, the composition is
 * automatically dropped so the explicit FK wins (an implicit
 * `->without('Alias')`).
 *
 * AddressFactory composes `City` via `configure()->forCity()`; the addresses
 * table's belongsTo `City` owns `city_id`. BillFactory composes both `Article`
 * and `Customer` via `configure()`; bills owns `article_id` and `customer_id`.
 */
class BaseFactorySkipComposeOnExplicitForeignKeyTest extends TestCase
{
    use TruncateDirtyTables;

    /**
     * @var array<int, array{level: int, message: string}>
     */
    private array $capturedWarnings = [];

    private bool $customErrorHandlerInstalled = false;

    public static function setUpBeforeClass(): void
    {
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
    }

    public static function tearDownAfterClass(): void
    {
        Configure::delete('FixtureFactories.testFixtureNamespace');
    }

    protected function tearDown(): void
    {
        Configure::delete('FixtureFactories.autoSkipComposeOnExplicitForeignKey');
        Configure::delete('FixtureFactories.warnOnAutoSkippedConfigureAssociation');
        if ($this->customErrorHandlerInstalled) {
            restore_error_handler();
            $this->customErrorHandlerInstalled = false;
        }
        DataCompiler::resetAutoSkippedConfigureAssociationWarnings();
        parent::tearDown();
    }

    /**
     * Pinning the configure()-composed parent's FK via `new([...])` drops the
     * composition for that build: the explicit value persists unchanged and no
     * parent row is created.
     */
    public function testExplicitForeignKeyViaNewSkipsComposition(): void
    {
        $city = CityFactory::new()->save();

        $address = AddressFactory::new(['city_id' => $city->id])->save();

        $this->assertSame($city->id, $address->city_id);
        $this->assertNull($address->city);
        $this->assertSame(1, CityFactory::query()->count());
    }

    /**
     * `setField()` is caller-supplied state too — same auto-skip as `new()`.
     */
    public function testExplicitForeignKeyViaSetFieldSkipsComposition(): void
    {
        $city = CityFactory::new()->save();

        $address = AddressFactory::new()->setField('city_id', $city->id)->save();

        $this->assertSame($city->id, $address->city_id);
        $this->assertSame(1, CityFactory::query()->count());
    }

    /**
     * `state()` (the patch layer) is caller-supplied state too — same
     * auto-skip.
     */
    public function testExplicitForeignKeyViaStateSkipsComposition(): void
    {
        $city = CityFactory::new()->save();

        $address = AddressFactory::new()->state(['city_id' => $city->id])->save();

        $this->assertSame($city->id, $address->city_id);
        $this->assertSame(1, CityFactory::query()->count());
    }

    /**
     * No explicit FK → the `configure()` composition still happens exactly as
     * before. A City is built and persisted, and the address points at it.
     */
    public function testWithoutExplicitForeignKeyCompositionStillHappens(): void
    {
        $address = AddressFactory::new()->save();

        $this->assertNotNull($address->city_id);
        $this->assertNotNull($address->city);
        $this->assertSame($address->city_id, $address->city->id);
        $this->assertSame(1, CityFactory::query()->count());
    }

    /**
     * An explicit `->with('Alias', $entity)` is an unambiguous request for
     * composition and wins over the auto-skip, even when the FK is also set.
     * The composed entity is the parent, and its id overrides the scalar FK.
     */
    public function testExplicitWithEntityStillComposesEvenWhenForeignKeySet(): void
    {
        $pinned = CityFactory::new()->save();
        $composed = CityFactory::new();

        $address = AddressFactory::new(['city_id' => $pinned->id])
            ->with('City', $composed)
            ->save();

        $this->assertNotNull($address->city);
        $this->assertNotSame($pinned->id, $address->city_id);
        $this->assertSame($address->city->id, $address->city_id);
        $this->assertSame(2, CityFactory::query()->count());
    }

    /**
     * An explicit `->with('Alias', [...])` array payload also still composes.
     */
    public function testExplicitWithArrayStillComposesEvenWhenForeignKeySet(): void
    {
        $pinned = CityFactory::new()->save();

        $address = AddressFactory::new(['city_id' => $pinned->id])
            ->with('City', ['name' => 'Composed City'])
            ->save();

        $this->assertNotNull($address->city);
        $this->assertSame('Composed City', $address->city->name);
        $this->assertNotSame($pinned->id, $address->city_id);
        $this->assertSame(2, CityFactory::query()->count());
    }

    /**
     * The opt-out flag restores the legacy behavior: the composed parent is
     * built and its fresh id overwrites the explicitly-set FK.
     */
    public function testOptOutRestoresLegacyOverrideBehavior(): void
    {
        Configure::write('FixtureFactories.autoSkipComposeOnExplicitForeignKey', false);

        $city = CityFactory::new()->save();

        $address = AddressFactory::new(['city_id' => $city->id])->save();

        $this->assertNotNull($address->city);
        $this->assertNotSame($city->id, $address->city_id);
        $this->assertSame($address->city->id, $address->city_id);
        $this->assertSame(2, CityFactory::query()->count());
    }

    /**
     * With multiple belongsTo composed in `configure()`, only the pinned one
     * is skipped. BillFactory composes both Article and Customer; pinning
     * `customer_id` skips Customer composition but Article is still composed.
     */
    public function testOnlyPinnedAssociationIsSkippedAmongMultipleBelongsTo(): void
    {
        $customer = CustomerFactory::new()->save();

        $bill = BillFactory::new(['customer_id' => $customer->id])->save();

        $this->assertSame($customer->id, $bill->customer_id);
        $this->assertNull($bill->customer);
        $this->assertSame(1, CustomerFactory::query()->count());

        // Article was NOT pinned, so it is still composed and persisted.
        $this->assertNotNull($bill->article_id);
        $this->assertNotNull($bill->article);
        $this->assertSame($bill->article_id, $bill->article->id);
    }

    /**
     * A `definition()` default for the FK column must NOT trigger the skip:
     * only caller-supplied state (enforced fields) counts. AddressFactory's
     * definition() returns only `street`, so the City composition stays in
     * effect when nothing pins `city_id`. (Guards the enforced-fields gate.)
     */
    public function testDefinitionDefaultDoesNotTriggerSkip(): void
    {
        $address = AddressFactory::new(['street' => 'Pinned Street'])->save();

        $this->assertSame('Pinned Street', $address->street);
        $this->assertNotNull($address->city);
        $this->assertSame($address->city_id, $address->city->id);
    }

    /**
     * Explicit `null` is intentionally out of scope: it is indistinguishable
     * from "not provided", so the legacy compose-then-overwrite behavior is
     * preserved and a parent is still composed. Callers wanting a genuine
     * orphan must use an explicit `->without('City')`.
     */
    public function testExplicitNullForeignKeyDoesNotSkipComposition(): void
    {
        $address = AddressFactory::new(['city_id' => null])->save();

        $this->assertNotNull($address->city);
        $this->assertSame($address->city_id, $address->city->id);
        $this->assertSame(1, CityFactory::query()->count());
    }

    /**
     * The documented escape hatch: pinning the FK and adding an explicit
     * `->without('City')` builds a genuine orphan with the requested null and
     * no parent composed. (Built, not persisted — the addresses schema has a
     * NOT NULL city_id, which is irrelevant to the composition behavior under
     * test here.)
     */
    public function testExplicitWithoutBuildsOrphanWithNullForeignKey(): void
    {
        $address = AddressFactory::new(['city_id' => null])->without('City')->build();

        $this->assertNull($address->city);
        $this->assertNull($address->city_id);
        $this->assertSame(0, CityFactory::query()->count());
    }

    /**
     * Pinning the root FK skips the entire composed sub-graph, not just the
     * direct parent. AddressFactory composes `City`, and CityFactory itself
     * composes `Countries` in its own configure(). Pinning `city_id` on the
     * address must drop the `City` composition outright — so no City AND no
     * Country are built beyond the one explicitly created for the pin.
     */
    public function testPinningRootForeignKeySkipsTheWholeComposedSubGraph(): void
    {
        $country = CountryFactory::new()->save();
        $city = CityFactory::new()->with('Countries', $country)->save();

        $address = AddressFactory::new(['city_id' => $city->id])->save();

        $this->assertSame($city->id, $address->city_id);
        $this->assertNull($address->city);
        $this->assertSame(1, CityFactory::query()->count());
        $this->assertSame(1, CountryFactory::query()->count());
    }

    public function testWarnsWhenConfiguredToExposeAutoSkippedDefaultAssociation(): void
    {
        Configure::write('FixtureFactories.warnOnAutoSkippedConfigureAssociation', true);
        $this->capturedWarnings = [];
        set_error_handler(
            function (int $level, string $message): bool {
                if ($level === E_USER_WARNING) {
                    $this->capturedWarnings[] = ['level' => $level, 'message' => $message];

                    return true;
                }

                return false;
            },
            E_USER_WARNING,
        );
        $this->customErrorHandlerInstalled = true;

        $city = CityFactory::new()->save();

        $address = AddressFactory::new(['city_id' => $city->id])->save();

        $this->assertSame($city->id, $address->city_id);
        $this->assertCount(1, $this->capturedWarnings);
        $this->assertStringContainsString(AddressFactory::class, $this->capturedWarnings[0]['message']);
        $this->assertStringContainsString('"city_id"', $this->capturedWarnings[0]['message']);
        $this->assertStringContainsString('"City"', $this->capturedWarnings[0]['message']);
    }

    public function testAutoSkipWarningIsDeduplicatedPerFactoryAssociation(): void
    {
        Configure::write('FixtureFactories.warnOnAutoSkippedConfigureAssociation', true);
        $this->capturedWarnings = [];
        set_error_handler(
            function (int $level, string $message): bool {
                if ($level === E_USER_WARNING) {
                    $this->capturedWarnings[] = ['level' => $level, 'message' => $message];

                    return true;
                }

                return false;
            },
            E_USER_WARNING,
        );
        $this->customErrorHandlerInstalled = true;

        $city = CityFactory::new()->save();

        AddressFactory::new(['city_id' => $city->id])->save();
        AddressFactory::new(['city_id' => $city->id])->save();

        $this->assertCount(1, $this->capturedWarnings);
    }
}
