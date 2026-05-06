<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\IdeHelper;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\IdeHelper\FactoryAnnotatorTask;
use IdeHelper\Annotator\AbstractAnnotator;
use IdeHelper\Annotator\ClassAnnotatorTask\PathAwareClassAnnotatorTaskInterface;
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
     * @param string $content
     *
     * @return string
     */
    protected function writeTempFactory(string $content): string
    {
        // Path must contain "/Factory/" for shouldRun() to match.
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fft_task_' . uniqid('', true) . DIRECTORY_SEPARATOR . 'Factory' . DIRECTORY_SEPARATOR;
        mkdir($dir, 0o777, true);
        $path = $dir . 'TempFactory.php';
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return void
     */
    public function testImplementsPathAwareInterface(): void
    {
        $this->assertContains(
            PathAwareClassAnnotatorTaskInterface::class,
            class_implements(FactoryAnnotatorTask::class),
        );
    }

    /**
     * @return void
     */
    public function testScanPathsAdvertisesTestsFactoryDirectory(): void
    {
        $this->assertSame(['tests/Factory/'], FactoryAnnotatorTask::scanPaths());
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
