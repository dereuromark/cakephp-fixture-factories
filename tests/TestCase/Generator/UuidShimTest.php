<?php
declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Generator;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

class UuidShimTest extends TestCase
{
    use TruncateDirtyTables;

    /**
     * Test that CountryFactory works with both generators when using uuid()
     *
     * @return void
     */
    public function testCountryFactoryWorksWithBothGenerators(): void
    {
        // Test with Faker (default)
        $country1 = CountryFactory::make()->getEntity();
        $this->assertIsString($country1->unique_stamp);
        $this->assertNotEmpty($country1->unique_stamp);

        // Test with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $country2 = CountryFactory::make()->getEntity();
        $this->assertIsString($country2->unique_stamp);
        $this->assertNotEmpty($country2->unique_stamp);

        // Ensure they generate different values
        $this->assertNotEquals($country1->unique_stamp, $country2->unique_stamp);

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test that persisting CountryFactory works with both generators
     *
     * @return void
     */
    public function testCountryFactoryPersistWorksWithBothGenerators(): void
    {
        // Test persisting with Faker (default)
        $country1 = CountryFactory::make()->persist();
        $this->assertIsString($country1->unique_stamp);
        $this->assertNotNull($country1->id);

        // Test persisting with DummyGenerator
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $country2 = CountryFactory::make()->persist();
        $this->assertIsString($country2->unique_stamp);
        $this->assertNotNull($country2->id);

        // Ensure they generate different values
        $this->assertNotEquals($country1->unique_stamp, $country2->unique_stamp);
        $this->assertNotEquals($country1->id, $country2->id);

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Test UUID format validation for both generators
     *
     * @return void
     */
    public function testUuidFormatValidation(): void
    {
        // Test Faker UUID format
        $fakerGenerator = CakeGeneratorFactory::create();
        $fakerUuid = $fakerGenerator->uuid();
        $this->assertTrue($this->isValidUuid($fakerUuid), "Faker UUID should be valid: $fakerUuid");

        // Test DummyGenerator UUID format
        Configure::write('FixtureFactories.generatorType', 'dummy');
        CakeGeneratorFactory::clearInstances();

        $dummyGenerator = CakeGeneratorFactory::create();
        $dummyUuid = $dummyGenerator->uuid();
        $this->assertTrue($this->isValidUuid($dummyUuid), "DummyGenerator UUID should be valid: $dummyUuid");

        // Reset config
        Configure::delete('FixtureFactories.generatorType');
        CakeGeneratorFactory::clearInstances();
    }

    /**
     * Helper method to validate UUID format
     *
     * @param string $uuid The UUID to validate
     * @return bool True if valid UUID format
     */
    private function isValidUuid(string $uuid): bool
    {
        // General UUID format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        // Accept any valid UUID format (not just v4)
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
