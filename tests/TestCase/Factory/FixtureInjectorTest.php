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

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use PHPUnit\Framework\Attributes\DataProvider;

class FixtureInjectorTest extends TestCase
{
    use TruncateDirtyTables;

    /**
     * With immutable factories, each derived factory keeps its own count.
     *
     * @return array
     */
    public static function createWithOneFactoryInTheDataProvider(): array
    {
        $Factory = ArticleFactory::make();

        return [
            [1, $Factory],
            [2, $Factory->setTimes(2)],
            [10, $Factory->setTimes(10)],
        ];
    }

    /**
     * For each test, a different factory is provided, so the expected
     * number of articles is the first parameter
     *
     * @return array<array>
     */
    public static function createWithDifferentFactoriesInTheDataProvider()
    {
        return [
            [1, ArticleFactory::make()],
            [2, ArticleFactory::make(2)],
            [10, ArticleFactory::make(10)],
        ];
    }

    /**
     * @param int $expectedCount
     * @param \CakephpFixtureFactories\Test\Factory\ArticleFactory $factory
     */
    #[DataProvider('createWithOneFactoryInTheDataProvider')]
    public function testCreateFactoryInTheDataProvider(int $expectedCount, ArticleFactory $factory): void
    {
        $factory->persist();
        $this->assertSame($expectedCount, ArticleFactory::query()->count());
    }

    /**
     * Since there are distinct factories in this data provider,
     * the factories will produce different set of data
                 *
     * @param int $n
     * @param \CakephpFixtureFactories\Test\Factory\ArticleFactory $factory
     */
    #[DataProvider('createWithDifferentFactoriesInTheDataProvider')]
    public function testCreateFactoryInTheDataProvider2(int $n, ArticleFactory $factory): void
    {
        $factory->persist();
        $this->assertSame($n, ArticleFactory::query()->count());
    }
}
