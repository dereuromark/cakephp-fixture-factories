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
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\SmellyAddressFactory;

/**
 * Covers the BaseFactory FK-in-definition() detector that emits an
 * E_USER_DEPRECATED when a factory's `definition()` returns a column belonging
 * to a belongsTo association — i.e. it tries to populate a FK as a scalar
 * default instead of composing the association via `->with()` / helpers.
 */
class BaseFactoryForeignKeyDetectionTest extends TestCase
{
    /**
     * @var array<int, array{level: int, message: string}>
     */
    private array $capturedErrors = [];

    protected function setUp(): void
    {
        parent::setUp();
        BaseFactory::resetForeignKeyInDefinitionDetector();
        Configure::write('FixtureFactories.strictDefinition', true);
        $this->capturedErrors = [];
        set_error_handler(
            function (int $level, string $message): bool {
                if ($level === E_USER_DEPRECATED) {
                    $this->capturedErrors[] = ['level' => $level, 'message' => $message];

                    return true;
                }

                return false;
            },
            E_USER_DEPRECATED,
        );
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        BaseFactory::resetForeignKeyInDefinitionDetector();
        Configure::delete('FixtureFactories.strictDefinition');
        parent::tearDown();
    }

    /**
     * Factories that return a belongsTo FK column from `definition()` are
     * misconfigured: the FK is meant to be populated by composing the parent
     * association, not by a scalar default. Detector raises a deprecation that
     * names the column, the association, and the migration path.
     */
    public function testTriggersDeprecationWhenDefinitionReturnsForeignKeyColumn(): void
    {
        SmellyAddressFactory::new()->build();

        $this->assertCount(1, $this->capturedErrors, 'Expected exactly one FK-in-definition deprecation.');
        $message = $this->capturedErrors[0]['message'];
        $this->assertStringContainsString(SmellyAddressFactory::class, $message);
        $this->assertStringContainsString('"city_id"', $message);
        $this->assertStringContainsString('"City"', $message);
        $this->assertStringContainsString("->with('City')", $message);
    }

    /**
     * Factories whose `definition()` only returns the entity's own scalar
     * columns (no FK columns) must not trip the detector. AddressFactory is
     * already shaped this way — its definition() returns `street` only and the
     * City association is composed via `configure()`.
     */
    public function testDoesNotTriggerForCleanFactory(): void
    {
        AddressFactory::new()->build();

        $this->assertSame([], $this->capturedErrors);
    }

    /**
     * Attaching `->with('City')` to a smelly factory must NOT suppress the
     * deprecation. The FK in definition() is still a smell — it either
     * silently overrides the composed parent's id with garbage, or creates a
     * dangling id if the association is later removed. The detector flags it
     * regardless so the lie cannot hide behind a passing test.
     */
    public function testWithAssociationDoesNotSuppressDeprecation(): void
    {
        SmellyAddressFactory::new()->with('City', CityFactory::new())->build();

        $this->assertCount(1, $this->capturedErrors);
        $this->assertStringContainsString('"city_id"', $this->capturedErrors[0]['message']);
    }

    /**
     * Projects mid-migration need an off switch so the deprecation doesn't
     * drown their suite. `FixtureFactories.strictDefinition = false` silences
     * the detector entirely. This flag is transitional and the next major
     * release will remove it together with promoting the deprecation to an
     * exception.
     */
    public function testOptOutSilencesDetector(): void
    {
        Configure::write('FixtureFactories.strictDefinition', false);

        SmellyAddressFactory::new()->build();

        $this->assertSame([], $this->capturedErrors);
    }

    /**
     * A naive implementation would re-fire on every `->build()` / `->save()`
     * call and bury the suite in duplicate messages. The detector dedupes per
     * (factory class, column) for the lifetime of the process, so a smelly
     * factory called N times produces exactly one deprecation per column.
     */
    public function testDeduplicatesPerFactoryAndColumn(): void
    {
        SmellyAddressFactory::new()->build();
        SmellyAddressFactory::new()->build();
        SmellyAddressFactory::new()->build();

        $this->assertCount(1, $this->capturedErrors, 'Expected the detector to fire only once per (factory, column).');
    }

    /**
     * The reset hook (used by this test's setUp/tearDown) re-arms the detector
     * for the next test. Without it, a single early-fire would mask all later
     * test cases that re-exercise the same factory+column pair.
     */
    public function testResetReArmsDetector(): void
    {
        SmellyAddressFactory::new()->build();
        $this->assertCount(1, $this->capturedErrors);

        BaseFactory::resetForeignKeyInDefinitionDetector();
        $this->capturedErrors = [];

        SmellyAddressFactory::new()->build();
        $this->assertCount(1, $this->capturedErrors);
    }
}
