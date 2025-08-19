<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

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
        $author1 = AuthorFactory::make()->getEntity();
        $author2 = AuthorFactory::make($author1)->getEntity();
        $this->assertSame($author1, $author2);
    }

    public function testMakeWithEntityPersisted(): void
    {
        $author1 = AuthorFactory::make()->persist();
        $author2 = AuthorFactory::make($author1)->persist();
        $author3Name = 'Foo';
        $author3 = AuthorFactory::make($author1)->setField('name', $author3Name)->persist();

        $this->assertSame($author1, $author2);
        $this->assertSame($author1->id, $author3->id);
        $this->assertSame($author3Name, $author3->name);
        $this->assertSame(1, AuthorFactory::count());
    }

    public function testMakeWithEntities(): void
    {
        $n = 2;
        $authors = AuthorFactory::make()->times($n)->persist();
        $authors2 = AuthorFactory::make($authors)->persist();
        $this->assertSame($n, count($authors2));
        $this->assertSame($authors, $authors2);
        $this->assertSame($n, AuthorFactory::count());
    }

    public function testWithWithEntity(): void
    {
        $address = AddressFactory::make()->persist();
        $author = AuthorFactory::make()->with('Address', $address)->persist();
        $this->assertSame($address, $author->get('address'));
        $this->assertSame($author->get('address_id'), $address->get('id'));
        $this->assertSame(1, AuthorFactory::count());
        $this->assertSame(1, AddressFactory::count());
    }

    public function testWithToOneWithEntities(): void
    {
        $n = 2;
        $addresses = AddressFactory::make()->times($n)->persist();
        $author = AuthorFactory::make()->with('Address', $addresses)->persist();
        $this->assertSame($addresses[0], $author->get('address'));
        $this->assertSame($author->get('address_id'), $addresses[0]->get('id'));
        $this->assertSame(1, AuthorFactory::count());
        $this->assertSame(2, AddressFactory::count());
    }

    public function testWithToManyWithEntities(): void
    {
        $n = 2;
        $articles = ArticleFactory::make()->times($n)->persist();
        $author = AuthorFactory::make()->withArticles($articles)->persist();

        $this->assertSame($articles, $author->get('articles'));
        $this->assertSame(ArticleFactory::DEFAULT_NUMBER_OF_AUTHORS * $n + 1, AuthorFactory::count());
        $this->assertSame(2, ArticleFactory::count());
    }

    public function testMakeEntityAndTimes(): void
    {
        $n = 2;
        $author1 = AuthorFactory::make()->persist();
        $authors = AuthorFactory::make($author1)->times($n)->persist();
        foreach ($authors as $author) {
            $this->assertSame($author1, $author);
        }
        $this->assertSame(1, AuthorFactory::count());
    }

    public function testWithEntitiesAndTimes(): void
    {
        $n = 2;
        $m = 3;
        $authors1 = AuthorFactory::make()->times($n)->persist();
        $authors = AuthorFactory::make($authors1)->times($m)->persist();

        $count = 0;
        for ($i = 0; $i < $m; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $this->assertSame($authors1[$j], $authors[$count]);
                $count++;
            }
        }
        $this->assertSame($n * $m, count($authors));
        $this->assertSame($n, AuthorFactory::count());
    }

    public function testMakeEntityWithoutDefaultAssociations(): void
    {
        $article1 = ArticleFactory::make()->persist();
        $this->assertSame(ArticleFactory::DEFAULT_NUMBER_OF_AUTHORS, count($article1->authors));
        ArticleFactory::make($article1)->persist();
        $this->assertSame(ArticleFactory::DEFAULT_NUMBER_OF_AUTHORS, count($article1->authors));
    }
}
