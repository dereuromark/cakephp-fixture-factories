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

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\DataCompiler;
use CakephpFixtureFactories\Factory\EventCollector;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\CustomBuildHooksArticleFactory;
use ReflectionMethod;

class BuildCollaboratorHooksTest extends TestCase
{
    public function testDefaultDataCompilerIsUsedWhenNotOverridden(): void
    {
        /** @var \CakephpFixtureFactories\Test\Factory\CustomBuildHooksArticleFactory $factory */
        $factory = ArticleFactory::new();

        $reflection = new ReflectionMethod($factory, 'getDataCompiler');
        $compiler = $reflection->invoke($factory);

        $this->assertSame(DataCompiler::class, get_class($compiler));
    }

    public function testDefaultEventCollectorIsUsedWhenNotOverridden(): void
    {
        $factory = ArticleFactory::new();

        $reflection = new ReflectionMethod($factory, 'getEventCompiler');
        $collector = $reflection->invoke($factory);

        $this->assertSame(EventCollector::class, get_class($collector));
    }

    public function testBuildDataCompilerHookIsUsedBySubclass(): void
    {
        $factory = CustomBuildHooksArticleFactory::new();

        $compiler = $factory->exposeDataCompiler();

        $this->assertInstanceOf(DataCompiler::class, $compiler);
        $this->assertNotSame(
            DataCompiler::class,
            get_class($compiler),
            'Expected an anonymous subclass of DataCompiler from the build hook',
        );
    }

    public function testBuildEventCollectorHookIsUsedBySubclass(): void
    {
        $factory = CustomBuildHooksArticleFactory::new();

        $collector = $factory->exposeEventCollector();

        $this->assertInstanceOf(EventCollector::class, $collector);
        $this->assertNotSame(
            EventCollector::class,
            get_class($collector),
            'Expected an anonymous subclass of EventCollector from the build hook',
        );
    }
}
