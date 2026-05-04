<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use CakephpFixtureFactories\Command\AnnotateFactoriesCommand;
use CakephpFixtureFactories\IdeHelper\FactoryAnnotatorTask;
use IdeHelper\Annotator\ClassAnnotatorTask\ClassAnnotatorTaskInterface;
use IdeHelper\Command\AnnotateCommand;

/**
 * Plugin class for CakephpFixtureFactories
 */
class CakephpFixtureFactoriesPlugin extends BasePlugin
{
    /**
     * Register a class annotator task with cakephp-ide-helper if it's installed,
     * so that `bin/cake annotate_factories` keeps every Factory subclass docblock
     * up-to-date with the canonical generic-extends form.
     *
     * No-op if cakephp-ide-helper is not present.
     *
     * @param \Cake\Core\PluginApplicationInterface<\Cake\Core\BasePlugin> $app
     *
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        if (!interface_exists(ClassAnnotatorTaskInterface::class)) {
            return;
        }

        /** @var array<string, string|null> $tasks */
        $tasks = (array)Configure::read('IdeHelper.classAnnotatorTasks', []);
        if (!array_key_exists('FactoryAnnotatorTask', $tasks)) {
            $tasks['FactoryAnnotatorTask'] = FactoryAnnotatorTask::class;
            Configure::write('IdeHelper.classAnnotatorTasks', $tasks);
        }
    }

    /**
     * Skip the default plugin command auto-discovery and register
     * AnnotateFactoriesCommand explicitly only when cakephp-ide-helper is
     * available. The command extends `IdeHelper\Command\AnnotateCommand`,
     * so PHP's autoloader would fatal during discovery if ide-helper is
     * not installed — even though the rest of this plugin works fine
     * without it.
     *
     * @param \Cake\Console\CommandCollection $commands
     *
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        if (class_exists(AnnotateCommand::class)) {
            $commands->add('annotate_factories', AnnotateFactoriesCommand::class);
        }

        return $commands;
    }
}
