<?php
declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Generator;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use TestApp\Model\Enum\TestStatus;

class GeneratorAdapterTest extends TestCase
{
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
}
