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

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use RuntimeException;

class BaseFactoryMakeWithEntityTest extends TestCase
{
    use TruncateDirtyTables;

    public static function setUpBeforeClass(): void
    {
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
    }

    public static function tearDownAfterClass(): void
    {
        Configure::delete('FixtureFactories.testFixtureNamespace');
    }

    public function dataProviderNoPersistOrPersist(): array
    {
        return [
            [true], [false],
        ];
    }

    public function testMakeWithEntity(): void
    {
        $author1 = AuthorFactory::new()->build();
        $author2 = AuthorFactory::new($author1)->build();
        $this->assertSame($author1, $author2);
    }

    public function testMakeWithEntityPersisted(): void
    {
        $author1 = AuthorFactory::new()->save();
        $author2 = AuthorFactory::new($author1)->save();
        $author3Name = 'Foo';
        $author3 = AuthorFactory::new($author1)->setField('name', $author3Name)->save();

        $this->assertSame($author1, $author2);
        $this->assertSame($author1->id, $author3->id);
        $this->assertSame($author3Name, $author3->name);
        $this->assertSame(1, AuthorFactory::query()->count());
    }

    public function testMakeWithEntities(): void
    {
        $n = 2;
        $authors = AuthorFactory::new($n)->saveMany();
        $authors2 = AuthorFactory::new($authors)->saveMany();
        $this->assertSame($n, count($authors2));
        $this->assertSame($authors, $authors2);
        $this->assertSame($n, AuthorFactory::query()->count());
    }

    public function testWithWithEntity(): void
    {
        $address = AddressFactory::new()->save();
        $author = AuthorFactory::new()->with('Address', $address)->save();
        $this->assertSame($address, $author->get('address'));
        $this->assertSame($author->get('address_id'), $address->get('id'));
        $this->assertSame(1, AuthorFactory::query()->count());
        $this->assertSame(1, AddressFactory::query()->count());
    }

    public function testWithToOneWithEntities(): void
    {
        $n = 2;
        $addresses = AddressFactory::new($n)->saveMany();

        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('expects exactly 1 entity');

        AuthorFactory::new()->with('Address', $addresses)->save();
    }

    public function testWithToManyWithEntities(): void
    {
        $n = 2;
        $articles = ArticleFactory::new($n)->saveMany();
        $author = AuthorFactory::new()->hasArticles($articles)->save();

        $this->assertSame($articles, $author->get('articles'));
        $this->assertSame(ArticleFactory::DEFAULT_NUMBER_OF_AUTHORS * $n + 1, AuthorFactory::query()->count());
        $this->assertSame(2, ArticleFactory::query()->count());
    }

    /**
     * Behavior change in v2: combining a single injected entity with a count
     * greater than 1 now throws. The previous behavior pushed the same
     * entity reference N times (each iteration mutated the same instance),
     * which was never useful in practice. The error message points at the
     * `new($entity->toArray())->count(N)` workaround.
     */
    public function testMakeEntityAndTimesIsRejected(): void
    {
        $author1 = AuthorFactory::new()->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot produce 2 entities from a single injected entity');

        AuthorFactory::new($author1, 2);
    }

    public function testWithEntitiesAndTimes(): void
    {
        $n = 2;
        $m = 3;
        $authors1 = AuthorFactory::new($n)->saveMany();
        $authors = AuthorFactory::new($authors1, $m)->saveMany();

        $count = 0;
        for ($i = 0; $i < $m; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $this->assertSame($authors1[$j], $authors[$count]);
                $count++;
            }
        }
        $this->assertSame($n * $m, count($authors));
        $this->assertSame($n, AuthorFactory::query()->count());
    }

    public function testMakeEntityWithoutDefaultAssociations(): void
    {
        $article1 = ArticleFactory::new()->save();
        $this->assertSame(ArticleFactory::DEFAULT_NUMBER_OF_AUTHORS, count($article1->authors));
        ArticleFactory::new($article1)->save();
        $this->assertSame(ArticleFactory::DEFAULT_NUMBER_OF_AUTHORS, count($article1->authors));
    }
}
