<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Command\AnnotateFactoriesCommand;

class AnnotateFactoriesCommandTest extends TestCase
{
    protected string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Path must contain a "/Factory/" segment so the task's shouldRun() matches.
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fft_cmd_' . uniqid('', true) . DIRECTORY_SEPARATOR . 'Factory' . DIRECTORY_SEPARATOR;
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // Clean up parent of "Factory/" too.
        $this->rrmdir(dirname(rtrim($this->tmpDir, DIRECTORY_SEPARATOR)));
        parent::tearDown();
    }

    /**
     * Recursive directory cleanup.
     *
     * @param string $dir
     *
     * @return void
     */
    protected function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Subclass that exposes the protected directory walker for testing.
     */
    protected function makeCommand(bool $dryRun = false): AnnotateFactoriesCommand
    {
        $command = new class extends AnnotateFactoriesCommand {
            public function annotateForTest(string $directory): bool
            {
                return $this->annotateDirectory($directory);
            }

            public function setForTest(Arguments $args, ConsoleIo $io): void
            {
                $this->args = $args;
                $this->io = $io;
            }
        };

        $args = new Arguments(
            [],
            [
                'dry-run' => $dryRun,
                'verbose' => false,
            ],
            [],
        );
        $io = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput());
        $command->setForTest($args, $io);

        return $command;
    }

    /**
     * @param string $relativePath
     * @param string $namespace
     * @param string $entityName
     *
     * @return string Absolute path of the written fixture file.
     */
    protected function writeLegacyFactory(string $relativePath, string $namespace, string $entityName): string
    {
        $path = $this->tmpDir . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $entityFqn = '\\' . $namespace . '\\' . $entityName;
        $entityFqnEscaped = str_replace('\\', '\\\\', $entityFqn);
        $namespaceLine = 'namespace ' . $namespace . '\\Test\\Factory;';
        $content = <<<PHP
<?php
declare(strict_types=1);

{$namespaceLine}

use CakephpFixtureFactories\\Factory\\BaseFactory as CakephpBaseFactory;

/**
 * {$entityName}Factory
 *
 * @method {$entityFqnEscaped} getEntity()
 * @method array<{$entityFqnEscaped}> getEntities()
 * @method {$entityFqnEscaped}|array<{$entityFqnEscaped}> persist()
 */
class {$entityName}Factory extends CakephpBaseFactory
{
}
PHP;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return void
     */
    public function testAnnotateDirectoryWalksSubdirectoriesRecursively(): void
    {
        $top = $this->writeLegacyFactory('InvoiceFactory.php', 'App', 'Invoice');
        $nested = $this->writeLegacyFactory('Admin/UserFactory.php', 'App\\Admin', 'User');

        $command = $this->makeCommand();
        $changed = $command->annotateForTest($this->tmpDir);

        $this->assertTrue($changed);
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>',
            (string)file_get_contents($top),
        );
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Admin\Model\Entity\User>',
            (string)file_get_contents($nested),
            'Nested factory under a subdirectory must also be reached and annotated.',
        );
    }

    /**
     * @return void
     */
    public function testAnnotateDirectoryIgnoresNonFactoryPhpFiles(): void
    {
        $factory = $this->writeLegacyFactory('InvoiceFactory.php', 'App', 'Invoice');
        $unrelated = $this->tmpDir . 'NotAFactory.php';
        $unrelatedSrc = "<?php\nclass NotAFactory {}\n";
        file_put_contents($unrelated, $unrelatedSrc);

        $command = $this->makeCommand();
        $command->annotateForTest($this->tmpDir);

        $this->assertSame(
            $unrelatedSrc,
            (string)file_get_contents($unrelated),
            'Files that do not end in Factory.php must be left untouched.',
        );
        $this->assertStringContainsString(
            '@extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>',
            (string)file_get_contents($factory),
        );
    }

    /**
     * @return void
     */
    public function testAnnotateDirectoryDryRunMakesNoOnDiskChanges(): void
    {
        $factory = $this->writeLegacyFactory('InvoiceFactory.php', 'App', 'Invoice');
        $before = (string)file_get_contents($factory);

        $command = $this->makeCommand(dryRun: true);
        $command->annotateForTest($this->tmpDir);

        $this->assertSame(
            $before,
            (string)file_get_contents($factory),
            '--dry-run must not write changes to disk.',
        );
    }
}
