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

    protected function writeFactoryFile(string $content): string
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'InvoiceFactory.php';
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
