<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Core\Plugin;
use CakephpFixtureFactories\IdeHelper\FactoryAnnotatorTask;
use IdeHelper\Annotator\AbstractAnnotator;
use IdeHelper\Command\AnnotateCommand;
use IdeHelper\Console\Io;

/**
 * Run the FactoryAnnotatorTask over every Factory subclass under the app's
 * tests/Factory directory and the same path inside each loaded local plugin
 * (vendor plugins are skipped).
 *
 * The default `bin/cake annotate classes` command only scans src/ and
 * tests/TestCase/, so without this dedicated entry point the
 * FactoryAnnotatorTask would never run on actual factory files.
 *
 * Usage:
 *
 *     bin/cake annotate_factories
 *     bin/cake annotate_factories -d # dry-run
 *     bin/cake annotate_factories -d --ci # CI gate (exit 2 if changes needed)
 *
 * Requires `dereuromark/cakephp-ide-helper` to be installed and loaded.
 */
class AnnotateFactoriesCommand extends AnnotateCommand
{
    /**
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Annotate Factory subclasses with the canonical generic-extends docblock for cakephp-fixture-factories.';
    }

    /**
     * @param \Cake\Console\Arguments $args
     * @param \Cake\Console\ConsoleIo $io
     *
     * @return int
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        parent::execute($args, $io);

        $paths = $this->collectFactoryPaths();
        $changed = false;

        foreach ($paths as $dir) {
            $changed = $this->annotateDirectory($dir) || $changed;
        }

        if ($args->getOption('ci') && $changed) {
            return static::CODE_CHANGES;
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Collect tests/Factory directories for the app and every loaded local plugin.
     *
     * @return array<string>
     */
    protected function collectFactoryPaths(): array
    {
        $paths = [];

        $appPath = ROOT . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Factory' . DIRECTORY_SEPARATOR;
        if (is_dir($appPath)) {
            $paths[] = $appPath;
        }

        foreach (Plugin::loaded() as $plugin) {
            $pluginPath = Plugin::path($plugin);
            $rootRelative = str_replace(ROOT . DIRECTORY_SEPARATOR, '', $pluginPath);
            if (str_starts_with($rootRelative, 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $factoryPath = $pluginPath . 'tests' . DIRECTORY_SEPARATOR . 'Factory' . DIRECTORY_SEPARATOR;
            if (is_dir($factoryPath)) {
                $paths[] = $factoryPath;
            }
        }

        return $paths;
    }

    /**
     * @param string $directory
     *
     * @return bool true if any file was modified
     */
    protected function annotateDirectory(string $directory): bool
    {
        $changed = false;
        $files = glob($directory . '*Factory.php') ?: [];

        foreach ($files as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if ($this->_shouldSkip($name, $path)) {
                continue;
            }

            $this->io->out(str_replace(ROOT . DIRECTORY_SEPARATOR, '', $path), 1, ConsoleIo::VERBOSE);

            $content = (string)file_get_contents($path);
            $task = new FactoryAnnotatorTask(new Io($this->io), $this->getTaskConfig(), $content);

            if (!$task->shouldRun($path, $content)) {
                continue;
            }
            if ($task->annotate($path)) {
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTaskConfig(): array
    {
        return [
            AbstractAnnotator::CONFIG_DRY_RUN => (bool)$this->args->getOption('dry-run'),
            AbstractAnnotator::CONFIG_VERBOSE => (bool)$this->args->getOption('verbose'),
        ];
    }
}
