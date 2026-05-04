<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\IdeHelper;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\IdeHelper\FactoryAnnotatorTask;
use IdeHelper\Annotator\AbstractAnnotator;
use IdeHelper\Console\Io;

class FactoryAnnotatorTaskTest extends TestCase
{
    protected Io $io;

    protected function setUp(): void
    {
        parent::setUp();

        $consoleIo = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput());
        $this->io = new Io($consoleIo);
    }

    /**
     * @param string $content
     *
     * @return \CakephpFixtureFactories\IdeHelper\FactoryAnnotatorTask
     */
    protected function getTask(string $content): FactoryAnnotatorTask
    {
        return new FactoryAnnotatorTask(
            $this->io,
            [
                AbstractAnnotator::CONFIG_DRY_RUN => true,
                AbstractAnnotator::CONFIG_VERBOSE => true,
            ],
            $content,
        );
    }

    /**
     * Write $content to a unique temp file and return its path.
     *
     * @param string $content
     *
     * @return string
     */
    protected function writeTempFactory(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fft_annotator_');
        if ($path === false) {
            $this->fail('Could not create temp file');
        }
        // tempnam creates without .php extension; rename so the path looks realistic.
        $finalPath = $path . '.php';
        rename($path, $finalPath);
        file_put_contents($finalPath, $content);

        return $finalPath;
    }

    /**
     * @return void
     */
    public function testShouldRunMatchesAppFactoryExtendingBaseFactoryViaUse(): void
    {
        $content = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

class InvoiceFactory extends BaseFactory
{
}
PHP;
        $result = $this->getTask($content)->shouldRun('/app/tests/Factory/InvoiceFactory.php', $content);
        $this->assertTrue($result);
    }

    /**
     * @return void
     */
    public function testShouldRunMatchesAliasedBaseFactoryUseStatement(): void
    {
        $content = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory as CakephpBaseFactory;

class InvoiceFactory extends CakephpBaseFactory
{
}
PHP;
        $result = $this->getTask($content)->shouldRun('/app/tests/Factory/InvoiceFactory.php', $content);
        $this->assertTrue($result);
    }

    /**
     * @return void
     */
    public function testShouldRunMatchesPluginFactory(): void
    {
        $content = <<<'PHP'
<?php
declare(strict_types=1);

namespace MyPlugin\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

class ThingFactory extends BaseFactory
{
}
PHP;
        $result = $this->getTask($content)->shouldRun('/plugins/MyPlugin/tests/Factory/ThingFactory.php', $content);
        $this->assertTrue($result);
    }

    /**
     * @return void
     */
    public function testShouldRunSkipsFileOutsideFactoryDirectory(): void
    {
        $content = <<<'PHP'
<?php
namespace App;

use CakephpFixtureFactories\Factory\BaseFactory;

class SomethingFactory extends BaseFactory
{
}
PHP;
        $result = $this->getTask($content)->shouldRun('/app/src/Something.php', $content);
        $this->assertFalse($result);
    }

    /**
     * @return void
     */
    public function testShouldRunSkipsFactoryFileNotExtendingBaseFactory(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Test\Factory;

class InvoiceFactory
{
}
PHP;
        $result = $this->getTask($content)->shouldRun('/app/tests/Factory/InvoiceFactory.php', $content);
        $this->assertFalse($result);
    }

    /**
     * @return void
     */
    public function testAnnotateInsertsExtendsForFreshAppFactory(): void
    {
        $content = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

/**
 * InvoiceFactory
 */
class InvoiceFactory extends BaseFactory
{
}
PHP;
        $task = $this->getTask($content);
        $path = $this->writeTempFactory($content);
        try {
            $changed = $task->annotate($path);
            $this->assertTrue($changed);
            $this->assertStringContainsString(
                '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>',
                $task->getContent(),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return void
     */
    public function testAnnotateInsertsExtendsForPluginFactory(): void
    {
        $content = <<<'PHP'
<?php
declare(strict_types=1);

namespace MyPlugin\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

/**
 * ThingFactory
 */
class ThingFactory extends BaseFactory
{
}
PHP;
        $task = $this->getTask($content);
        $path = $this->writeTempFactory($content);
        try {
            $changed = $task->annotate($path);
            $this->assertTrue($changed);
            $this->assertStringContainsString(
                '@extends \CakephpFixtureFactories\Factory\BaseFactory<\MyPlugin\Model\Entity\Thing>',
                $task->getContent(),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return void
     */
    public function testAnnotateStripsLegacyMethodBlock(): void
    {
        $content = <<<'PHP'
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
 * @method static \App\Model\Entity\Invoice get(mixed $primaryKey, array $options = [])
 */
class InvoiceFactory extends CakephpBaseFactory
{
}
PHP;
        $task = $this->getTask($content);
        $path = $this->writeTempFactory($content);
        try {
            $changed = $task->annotate($path);
            $this->assertTrue($changed);

            $newContent = $task->getContent();
            $this->assertStringNotContainsString('@method \App\Model\Entity\Invoice getEntity()', $newContent);
            $this->assertStringNotContainsString('@method array<\App\Model\Entity\Invoice> getEntities()', $newContent);
            $this->assertStringNotContainsString('persist()', $newContent);
            $this->assertStringContainsString(
                '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>',
                $newContent,
            );
            // Static get(...) line is preserved (Table proxy, not generic).
            $this->assertStringContainsString(
                '@method static \App\Model\Entity\Invoice get(mixed $primaryKey, array $options = [])',
                $newContent,
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return void
     */
    public function testAnnotateIsIdempotentOnAlreadyMigratedFactory(): void
    {
        $content = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory as CakephpBaseFactory;

/**
 * InvoiceFactory
 *
 * @extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>
 * @method static \App\Model\Entity\Invoice get(mixed $primaryKey, array $options = [])
 */
class InvoiceFactory extends CakephpBaseFactory
{
}
PHP;
        $task = $this->getTask($content);
        $path = $this->writeTempFactory($content);
        try {
            $changed = $task->annotate($path);
            $this->assertFalse($changed, 'Already-migrated factory should produce no changes');
            $this->assertSame($content, $task->getContent());
        } finally {
            @unlink($path);
        }
    }
}
