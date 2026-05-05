<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\IdeHelper;

use Cake\TestSuite\TestCase;

class AnnotationMigrationScriptTest extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fft_script_' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testScriptMigratesFactoryUsingImportedBaseFactory(): void
    {
        $file = $this->writeFactoryFile(<<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

/**
 * InvoiceFactory
 *
 * @method \App\Model\Entity\Invoice getEntity()
 * @method array<\App\Model\Entity\Invoice> getEntities()
 * @method \App\Model\Entity\Invoice|array<\App\Model\Entity\Invoice> persist()
 */
class InvoiceFactory extends BaseFactory
{
}
PHP);

        $output = $this->runScript($file);

        $this->assertStringContainsString('[migrated]', $output);
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>',
            (string)file_get_contents($file),
        );
    }

    public function testScriptMigratesFactoryUsingAliasedImportedBaseFactory(): void
    {
        $file = $this->writeFactoryFile(<<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory as CakephpBaseFactory;

/**
 * InvoiceFactory
 *
 * @method \App\Model\Entity\Invoice getEntity()
 * @method array<\App\Model\Entity\Invoice> getEntities()
 * @method \App\Model\Entity\Invoice|array<\App\Model\Entity\Invoice> persist()
 */
class InvoiceFactory extends CakephpBaseFactory
{
}
PHP);

        $output = $this->runScript($file);

        $this->assertStringContainsString('[migrated]', $output);
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>',
            (string)file_get_contents($file),
        );
    }

    /**
     * When the factory targets a plugin table via "Plugin.Table" the script
     * resolves the entity inside that plugin's namespace, even if the factory
     * itself lives under App\Test\Factory.
     */
    public function testScriptResolvesCrossPluginEntityFromRegistryName(): void
    {
        // CustomerFactory lives under App but targets the TestPlugin's Customer table.
        // The TestApp ships TestPlugin\Model\Entity\Customer, which exists at
        // class_exists() time once vendor/autoload.php is loaded.
        $file = $this->writeFactoryFile(<<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * CustomerFactory
 *
 * @method \Cake\Datasource\EntityInterface getEntity()
 * @method array<\Cake\Datasource\EntityInterface> getEntities()
 * @method \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface> persist()
 */
class CustomerFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'TestPlugin.Customers';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [];
    }
}
PHP);

        $output = $this->runScript($file);

        $this->assertStringContainsString('[migrated]', $output);
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\TestPlugin\Model\Entity\Customer>',
            (string)file_get_contents($file),
        );
    }

    /**
     * If a plugin hint is present but neither the plugin's entity nor the
     * namespace candidate resolves to a real class, the script falls back
     * to EntityInterface rather than emitting a phantom FQN that PHPStan
     * would later reject.
     */
    public function testScriptFallsBackToEntityInterfaceWhenPluginEntityIsMissing(): void
    {
        $file = $this->writeFactoryFile(<<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * MysteryThingFactory
 *
 * @method \Cake\Datasource\EntityInterface getEntity()
 * @method array<\Cake\Datasource\EntityInterface> getEntities()
 * @method \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface> persist()
 */
class MysteryThingFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        // NoSuchPlugin\Model\Entity\MysteryThing does not exist.
        return 'NoSuchPlugin.MysteryThings';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [];
    }
}
PHP, 'MysteryThingFactory.php');

        $output = $this->runScript($file);

        $this->assertStringContainsString('[migrated]', $output);
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>',
            (string)file_get_contents($file),
        );
    }

    /**
     * Without a plugin hint the script keeps the original namespace-convention
     * heuristic, even if the resulting class doesn't autoload. This preserves
     * backward compatibility for existing factories that were happily relying
     * on the convention before the cross-plugin fix landed.
     */
    public function testScriptKeepsNamespaceCandidateWhenNoPluginHint(): void
    {
        $file = $this->writeFactoryFile(<<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * GhostFactory
 *
 * @method \Cake\Datasource\EntityInterface getEntity()
 * @method array<\Cake\Datasource\EntityInterface> getEntities()
 * @method \Cake\Datasource\EntityInterface|array<\Cake\Datasource\EntityInterface> persist()
 */
class GhostFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Ghosts';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [];
    }
}
PHP, 'GhostFactory.php');

        $output = $this->runScript($file);

        $this->assertStringContainsString('[migrated]', $output);
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Ghost>',
            (string)file_get_contents($file),
        );
    }

    protected function writeFactoryFile(string $content, string $name = 'InvoiceFactory.php'): string
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);

        return $path;
    }

    protected function runScript(string $path): string
    {
        $script = dirname(__DIR__, 3) . '/bin/migrate-factory-annotations.php';
        $command = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg($path) . ' 2>&1';

        return (string)shell_exec($command);
    }
}
