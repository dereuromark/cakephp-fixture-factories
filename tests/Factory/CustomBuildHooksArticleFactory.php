<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 3.2.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\DataCompiler;
use CakephpFixtureFactories\Factory\EventCollector;

/**
 * Exercises the buildDataCompiler() / buildEventCollector() extension points
 * on BaseFactory. Substitutes marker subclasses so tests can assert the hooks
 * were actually used.
 */
class CustomBuildHooksArticleFactory extends ArticleFactory
{
    protected function buildDataCompiler(): DataCompiler
    {
        return new class ($this) extends DataCompiler {
        };
    }

    protected function buildEventCollector(): EventCollector
    {
        return new class ($this->getRootTableRegistryName()) extends EventCollector {
        };
    }

    public function exposeDataCompiler(): DataCompiler
    {
        return $this->getDataCompiler();
    }

    public function exposeEventCollector(): EventCollector
    {
        return $this->getEventCompiler();
    }
}
