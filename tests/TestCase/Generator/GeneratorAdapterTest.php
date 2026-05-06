<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Generator;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use CakephpFixtureFactories\Test\Factory\AlternativeSeedArticleFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use InvalidArgumentException;
use OverflowException;
use TestApp\Model\Enum\EmptyTestStatus;
use TestApp\Model\Enum\SingleCaseStatus;
use TestApp\Model\Enum\TestStatus;
use TestApp\Model\Enum\UnitTestStatus;

class GeneratorAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset generator state for consistent test isolation
        CakeGeneratorFactory::clearInstances();
        BaseFactory::resetDefaultGenerator();
    }

    protected function tearDown(): void
    {
        // Clean up any config changes made during tests
        Configure::delete('FixtureFactories.instanceLevelGenerator');
        Configure::delete('FixtureFactories.seed');
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
        BaseFactory::resetDefaultGenerator();

        parent::tearDown();
    }

    /**
     * Test backward compatibility with Faker
     *
     * @return void
     */
    public function testFakerBackwardCompatibility(): void
    {
        // Default should be Faker
        $generator = CakeGeneratorFactory::create();
        $this->assertInstanceOf(GeneratorInterface::class, $generator);

        // Test common Faker methods work
        $this->assertIsString($generator->name());
        $this->assertIsString($generator->email());
        $this->assertIsString($generator->text());
        $this->assertIsInt($generator->randomNumber());
        $this->assertIsBool($generator->boolean());

        // Test seeding works
        $generator->seed(1234);
        $value1 = $generator->randomNumber();

        $generator2 = CakeGeneratorFactory::create();
        $generator2->seed(1234);
        $value2 = $generator2->randomNumber();

        $this->assertEquals($value1, $value2);
    }

    /**
     * Test switching to DummyGenerator
     *
     * @return void
     */
    public function testDummyGeneratorAdapter(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $generator = CakeGeneratorFactory::create();
        $this->assertInstanceOf(GeneratorInterface::class, $generator);

        // Test common methods work with DummyGenerator
        $this->assertIsString($generator->name());
        $this->assertIsString($generator->email());
        $this->assertIsString($generator->word());

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test unique() method works
     *
     * @return void
     */
    public function testUniqueGenerator(): void
    {
        $generator = CakeGeneratorFactory::create();
        $unique = $generator->unique();

        $this->assertInstanceOf(GeneratorInterface::class, $unique);

        // Generate some unique values
        $values = [];
        for ($i = 0; $i < 10; $i++) {
            $values[] = $unique->randomDigit();
        }

        // Should have 10 unique values
        $this->assertCount(10, array_unique($values));
    }

    /**
     * Test optional() method works
     *
     * @return void
     */
    public function testOptionalGenerator(): void
    {
        $generator = CakeGeneratorFactory::create();
        $optional = $generator->optional(0.5);

        $this->assertInstanceOf(GeneratorInterface::class, $optional);

        // Generate some values - some should be null
        $hasNull = false;
        $hasValue = false;

        for ($i = 0; $i < 100; $i++) {
            $value = $optional->randomNumber();
            /** @phpstan-ignore-next-line */
            if ($value === null) {
                $hasNull = true;
            } else {
                $hasValue = true;
            }

            /** @phpstan-ignore-next-line */
            if ($hasNull && $hasValue) {
                break;
            }
        }

        $this->assertTrue($hasNull, 'Optional generator should produce some null values');
        $this->assertTrue($hasValue, 'Optional generator should produce some actual values');
    }

    /**
     * Test locale support
     *
     * @return void
     */
    public function testLocaleSupport(): void
    {
        // Test with a specific locale
        $generator = CakeGeneratorFactory::create('fr_FR');
        $this->assertInstanceOf(GeneratorInterface::class, $generator);

        // The name should work regardless of locale
        $this->assertIsString($generator->name());
    }

    /**
     * Regression: BaseFactory's locale-resolution path used to short-circuit
     * `Configure::read('FixtureFactories.defaultLocale')` by passing
     * `I18n::getLocale()` (which is never null) as the explicit param. The
     * Configure key was therefore unreachable. Centralising in
     * `BaseFactory::resolveLocale()` flips the precedence to:
     * explicit > Configure > I18n.
     *
     * We can't easily inspect the locale on the underlying Generator without
     * reflection (Faker stores it on the locale-specific provider list), so
     * this test asserts the cache-key partitioning instead: enabling the
     * Configure key must produce a different cached generator instance than
     * the one cached against I18n's locale.
     *
     * @return void
     */
    public function testConfigureDefaultLocaleIsHonoured(): void
    {
        BaseFactory::resetDefaultGenerator();
        Configure::delete('FixtureFactories.defaultLocale');

        // Prime the cache against I18n's default locale.
        $factory = ArticleFactory::new();
        $genWithoutConfigure = $factory->getGenerator();

        // Now set a different locale via Configure and reset the cache.
        BaseFactory::resetDefaultGenerator();
        Configure::write('FixtureFactories.defaultLocale', 'fr_FR');

        $factory2 = ArticleFactory::new();
        $genWithConfigure = $factory2->getGenerator();

        $this->assertNotSame(
            $genWithoutConfigure,
            $genWithConfigure,
            'Configure::FixtureFactories.defaultLocale must produce a distinct generator from the I18n fallback.',
        );

        Configure::delete('FixtureFactories.defaultLocale');
    }

    /**
     * Test UUID generation works with both Faker and DummyGenerator
     *
     * @return void
     */
    public function testUuidGenerationCrossAdapter(): void
    {
        // Test with Faker (default)
        $fakerGenerator = CakeGeneratorFactory::create();
        $fakerUuid = $fakerGenerator->uuid();
        $this->assertIsString($fakerUuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $fakerUuid);

        // Test with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $dummyGenerator = CakeGeneratorFactory::create();
        $dummyUuid = $dummyGenerator->uuid();
        $this->assertIsString($dummyUuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $dummyUuid);

        // Ensure they generate different values
        $this->assertNotEquals($fakerUuid, $dummyUuid);

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test UUID generation with unique() modifier works with DummyGenerator
     *
     * @return void
     */
    public function testUuidGenerationWithUniqueAdapter(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $generator = CakeGeneratorFactory::create();
        $uniqueGenerator = $generator->unique();

        // Generate multiple unique UUIDs
        $uuids = [];
        for ($i = 0; $i < 5; $i++) {
            $uuid = $uniqueGenerator->uuid();
            $this->assertIsString($uuid);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
            $uuids[] = $uuid;
        }

        // Ensure all UUIDs are unique
        $this->assertCount(5, array_unique($uuids));

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test enumValue() method works with both adapters
     *
     * @return void
     */
    public function testEnumValueGeneration(): void
    {
        // Test with Faker
        $fakerGenerator = CakeGeneratorFactory::create();
        $fakerValue = $fakerGenerator->enumValue(TestStatus::class);
        $this->assertIsString($fakerValue);
        $this->assertContains($fakerValue, ['active', 'inactive', 'pending']);

        // Test with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $dummyGenerator = CakeGeneratorFactory::create();
        $dummyValue = $dummyGenerator->enumValue(TestStatus::class);
        $this->assertIsString($dummyValue);
        $this->assertContains($dummyValue, ['active', 'inactive', 'pending']);

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test enumElement() method works with both adapters
     *
     * @return void
     */
    public function testEnumElementGeneration(): void
    {
        // Test with Faker
        $fakerGenerator = CakeGeneratorFactory::create();
        $fakerElement = $fakerGenerator->enumElement(TestStatus::class);
        $this->assertInstanceOf(TestStatus::class, $fakerElement);
        $this->assertContains($fakerElement, TestStatus::cases());

        // Test with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $dummyGenerator = CakeGeneratorFactory::create();
        $dummyElement = $dummyGenerator->enumElement(TestStatus::class);
        $this->assertInstanceOf(TestStatus::class, $dummyElement);
        $this->assertContains($dummyElement, TestStatus::cases());

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test enum methods with unique() modifier
     *
     * @return void
     */
    public function testEnumWithUnique(): void
    {
        // Test with Faker
        $fakerGenerator = CakeGeneratorFactory::create()->unique();
        $values = [];
        for ($i = 0; $i < 3; $i++) {
            $values[] = $fakerGenerator->enumValue(TestStatus::class);
        }
        $this->assertCount(3, array_unique($values));

        // Test with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $dummyGenerator = CakeGeneratorFactory::create()->unique();
        $values = [];
        for ($i = 0; $i < 3; $i++) {
            $values[] = $dummyGenerator->enumValue(TestStatus::class);
        }
        $this->assertCount(3, array_unique($values));

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test enumCase() method works with both adapters
     *
     * @return void
     */
    public function testEnumCaseGeneration(): void
    {
        // Test with Faker
        $fakerGenerator = CakeGeneratorFactory::create();
        $fakerCase = $fakerGenerator->enumCase(TestStatus::class);
        $this->assertInstanceOf(TestStatus::class, $fakerCase);
        $this->assertContains($fakerCase, TestStatus::cases());

        // Test with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $dummyGenerator = CakeGeneratorFactory::create();
        $dummyCase = $dummyGenerator->enumCase(TestStatus::class);
        $this->assertInstanceOf(TestStatus::class, $dummyCase);
        $this->assertContains($dummyCase, TestStatus::cases());

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test realText() method works with both adapters
     *
     * @return void
     */
    public function testRealTextGeneration(): void
    {
        // Test with Faker
        $fakerGenerator = CakeGeneratorFactory::create();
        $fakerText = $fakerGenerator->realText(100);
        $this->assertIsString($fakerText);
        $this->assertLessThanOrEqual(100, strlen($fakerText));

        // Test with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $dummyGenerator = CakeGeneratorFactory::create();
        $dummyText = $dummyGenerator->realText(100);
        $this->assertIsString($dummyText);
        $this->assertLessThanOrEqual(100, strlen($dummyText));

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test UUID generation differences between adapters
     *
     * @return void
     */
    public function testUuidFormatConsistency(): void
    {
        // Test both adapters generate valid UUID v4 format
        $adapters = [
            'faker' => null,
            'dummy' => 'dummy',
        ];

        foreach ($adapters as $name => $config) {
            if ($config) {
                Configure::write('FixtureFactories.generatorType', $config);
            }
            CakeGeneratorFactory::clearInstances();

            $generator = CakeGeneratorFactory::create();
            for ($i = 0; $i < 5; $i++) {
                $uuid = $generator->uuid();
                $this->assertIsString($uuid, "UUID from $name should be a string");
                // Different adapters may use different UUID versions
                // Faker may not always generate v4, so just check general UUID format
                $this->assertMatchesRegularExpression(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                    $uuid,
                    "UUID from $name should match general UUID format",
                );
            }

            if ($config) {
                Configure::delete('FixtureFactories.generatorType');
            }
        }

        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test seeding works with DummyGenerator
     *
     * @return void
     */
    public function testDummyGeneratorSeeding(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        // First generator with seed
        $generator1 = CakeGeneratorFactory::create();
        $generator1->seed(12345);
        $value1a = $generator1->randomNumber();
        $value1b = $generator1->randomNumber();
        $uuid1 = $generator1->uuid();

        // Clear instances to force recreation
        CakeGeneratorFactory::clearInstances();

        // Second generator with same seed
        $generator2 = CakeGeneratorFactory::create();
        $generator2->seed(12345);
        $value2a = $generator2->randomNumber();
        $value2b = $generator2->randomNumber();
        $uuid2 = $generator2->uuid();

        // Values should be the same due to seeding
        $this->assertEquals($value1a, $value2a, 'First random numbers should be the same');
        $this->assertEquals($value1b, $value2b, 'Second random numbers should be the same');
        $this->assertEquals($uuid1, $uuid2, 'Seeded generators should produce same UUID');

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test that setGenerator affects all factories globally by default (BC)
     *
     * @return void
     */
    public function testSetGeneratorGlobalByDefault(): void
    {
        $factory1 = ArticleFactory::new();
        $factory1->setGenerator('dummy');

        // A different factory instance should also get the dummy generator
        $factory2 = ArticleFactory::new();

        // Both should return the same generator instance (global default)
        $this->assertSame($factory1->getGenerator(), $factory2->getGenerator());
    }

    /**
     * Test that setGenerator only affects current instance when feature flag is enabled
     *
     * @return void
     */
    public function testSetGeneratorInstanceLevel(): void
    {
        Configure::write('FixtureFactories.instanceLevelGenerator', true);

        $factory1 = ArticleFactory::new();
        $factory1 = $factory1->setGenerator('dummy');

        $factory2 = ArticleFactory::new();

        // factory1 should have its own generator
        // factory2 should fall back to the default (faker)
        $this->assertNotSame($factory1->getGenerator(), $factory2->getGenerator());
    }

    /**
     * Test setDefaultGenerator always sets global default
     *
     * @return void
     */
    public function testSetDefaultGenerator(): void
    {
        Configure::write('FixtureFactories.instanceLevelGenerator', true);

        // setDefaultGenerator should set the global default even with flag enabled
        BaseFactory::setDefaultGenerator('dummy');

        $factory = ArticleFactory::new();
        $generator = $factory->getGenerator();

        // Should use the default generator (dummy) since no instance override
        $this->assertIsString($generator->name());
    }

    /**
     * Test resetDefaultGenerator clears the cached default
     *
     * @return void
     */
    public function testResetDefaultGenerator(): void
    {
        // Get a generator to cache it
        $factory1 = ArticleFactory::new();
        $gen1 = $factory1->getGenerator();

        // Reset
        BaseFactory::resetDefaultGenerator();
        CakeGeneratorFactory::clearInstances();

        // Getting again should create a new instance
        $factory2 = ArticleFactory::new();
        $gen2 = $factory2->getGenerator();

        $this->assertNotSame($gen1, $gen2);
    }

    public function testDifferentFactorySeedsDoNotPoisonSharedDefaultGeneratorCache(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();
        BaseFactory::resetDefaultGenerator();

        $defaultGenerator = ArticleFactory::new()->getGenerator();
        $alternativeGenerator = AlternativeSeedArticleFactory::new()->getGenerator();

        $this->assertNotSame($defaultGenerator, $alternativeGenerator);
        $this->assertNotSame($defaultGenerator->randomNumber(), $alternativeGenerator->randomNumber());
    }

    /**
     * The `FixtureFactories.seed` config is backend-agnostic. We assert it
     * against the Dummy backend whose XoshiroRandomizer is seeded directly
     * — Faker relies on PHP's process-global `mt_srand`, which is sensitive
     * to test execution order and underlying PHP/Faker versions on the
     * lowest-dependency CI matrix and produces flakes there.
     *
     * @return void
     */
    public function testConfigurableSeed(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        Configure::write('FixtureFactories.seed', 9999);

        $factory = ArticleFactory::new();
        $gen = $factory->getGenerator();
        $value1 = $gen->randomNumber();

        // Reset and recreate with same seed
        CakeGeneratorFactory::clearInstances();
        BaseFactory::resetDefaultGenerator();

        $factory2 = ArticleFactory::new();
        $gen2 = $factory2->getGenerator();
        $value2 = $gen2->randomNumber();

        $this->assertSame($value1, $value2, 'Same seed should produce same values');
    }

    /**
     * Regression: $gen->name (Faker-style property access) must work on the
     * Dummy adapter too. Previously DummyGeneratorAdapter::__get gated on
     * method_exists() against the underlying DummyGenerator — but DummyGenerator
     * dispatches via __call, so method_exists returns false for almost
     * everything and __get threw. Cross-backend definition() code broke.
     *
     * @return void
     */
    public function testDummyGeneratorPropertyAccess(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $generator = CakeGeneratorFactory::create();

        $this->assertIsString($generator->name);
        $this->assertIsString($generator->email);
        $this->assertIsString($generator->word);
    }

    /**
     * Regression: $gen->optional()->unique() on the Dummy adapter must
     * actually deduplicate. Previously DummyOptionalAdapter::unique() returned
     * a fresh DummyUniqueAdapter wrapping the parent generator without setting
     * its isUnique flag, so duplicates leaked through.
     *
     * @return void
     */
    public function testDummyOptionalUniqueDoesDedup(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $generator = CakeGeneratorFactory::create();
        $unique = $generator->optional(1.0)->unique();

        $values = [];
        for ($i = 0; $i < 10; $i++) {
            $values[] = $unique->randomDigit();
        }

        // randomDigit() has 10 possible values; with optional(1.0) all return
        // a value (never null) so unique() must dedup all 10.
        $this->assertCount(10, array_unique($values));
    }

    /**
     * Regression: DummyOptionalAdapter::shouldReturnValue previously used
     * mt_rand() which bypassed the seeded randomizer. Two generators with the
     * same seed must produce the same optional() draw sequence.
     *
     * @return void
     */
    public function testDummyOptionalRespectsSeed(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $gen1 = CakeGeneratorFactory::create();
        $gen1->seed(42);
        $optional1 = $gen1->optional(0.5);
        $sequence1 = [];
        for ($i = 0; $i < 20; $i++) {
            $sequence1[] = $optional1->randomDigit();
        }

        CakeGeneratorFactory::clearInstances();

        $gen2 = CakeGeneratorFactory::create();
        $gen2->seed(42);
        $optional2 = $gen2->optional(0.5);
        $sequence2 = [];
        for ($i = 0; $i < 20; $i++) {
            $sequence2[] = $optional2->randomDigit();
        }

        $this->assertSame($sequence1, $sequence2, 'Seeded optional() should produce identical draw sequences');
    }

    public function testUnknownGeneratorTypeThrows(): void
    {
        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('Unknown generator type `unknown`');

        CakeGeneratorFactory::create(null, 'unknown');
    }

    public function testMissingGeneratorAdapterClassThrows(): void
    {
        CakeGeneratorFactory::registerAdapter('missing_class', 'Nope\\MissingGeneratorAdapter');

        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('Generator adapter class `Nope\\MissingGeneratorAdapter` not found');

        CakeGeneratorFactory::create(null, 'missing_class');
    }

    public function testDummyUniqueBooleanExhaustionThrowsOverflow(): void
    {
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $generator = CakeGeneratorFactory::create()->unique();
        $generator->boolean();
        $generator->boolean();

        $this->expectException(OverflowException::class);
        $generator->boolean();
    }

    public function testFakerUniqueEnumRejectsNonEnumClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid enum class');

        CakeGeneratorFactory::create()->unique()->enumValue('not-an-enum');
    }

    public function testFakerUniqueEnumValueRejectsNonBackedEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only backed enums are supported');

        CakeGeneratorFactory::create()->unique()->enumValue(UnitTestStatus::class);
    }

    public function testFakerUniqueEnumRejectsEmptyEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enum has no cases');

        CakeGeneratorFactory::create()->unique()->enumCase(EmptyTestStatus::class);
    }

    public function testFakerUniqueEnumExhaustionThrowsOverflow(): void
    {
        $generator = CakeGeneratorFactory::create()->unique();
        $value = $generator->enumValue(SingleCaseStatus::class);
        $this->assertSame('single', $value);

        $this->expectException(OverflowException::class);
        $generator->enumValue(SingleCaseStatus::class);
    }
}
