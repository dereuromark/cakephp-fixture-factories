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

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\PluginApplicationInterface;
use CakephpFixtureFactories\IdeHelper\FactoryAnnotatorTask;
use IdeHelper\Annotator\ClassAnnotatorTask\ClassAnnotatorTaskInterface;

/**
 * Plugin class for CakephpFixtureFactories
 */
class CakephpFixtureFactoriesPlugin extends BasePlugin
{
    /**
     * Register the FactoryAnnotatorTask with cakephp-ide-helper if it's
     * installed. The task implements `PathAwareClassAnnotatorTaskInterface`,
     * so once registered the standard `bin/cake annotate classes` (and
     * `annotate all`) command walks `tests/Factory/` automatically and
     * keeps every Factory subclass docblock in sync with the canonical
     * generic-extends form.
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
}
