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
use Cake\Utility\Hash;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\ArticleWithFiveBillsFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use TestApp\Model\Entity\Author;

class BaseFactoryDefaultValuesTest extends TestCase
{
    public function testMakeAuthorWithDefaultName(): void
    {
        $author = AuthorFactory::make()->getEntity();
        $this->assertTrue(is_string($author->name));
        $this->assertTrue(is_string($author->address->street));
        $this->assertTrue(is_string($author->address->city->name));
        $this->assertTrue(is_string($author->address->city->country->name));
    }

    public function testMakeAuthorWithArticlesWithDefaultTitles(): void
    {
        $n = 2;
        $author = AuthorFactory::make()->withArticles($n)->getEntity();
        $this->assertTrue(is_string($author->name));
        foreach ($author->articles as $article) {
            $this->assertTrue(is_string($article->title));
        }
    }

    public function testPersistAddressWithCityAndCountry(): void
    {
        $address = AddressFactory::make()->persist();

        $this->assertTrue(is_string($address->street));
        $this->assertTrue(is_string($address->city->name));
        $this->assertTrue(is_string($address->city->country->name));
        $this->assertTrue(is_numeric($address->city_id));
        $this->assertTrue(is_numeric($address->city->country_id));
    }

    public function testChildAssociation(): void
    {
        $article = ArticleWithFiveBillsFactory::make()->getEntity();

        $this->assertInstanceOf(Author::class, $article->authors[0]);
        $this->assertSame(5, count($article->bills));
    }

    /**
     * PatchData should overwrite the data passed
     * in the instantiation
     */
    public function testPatchDataAndCallable(): void
    {
        $n = 2;
        $title = 'Some title';
        $articles = ArticleFactory::makeWith(function (ArticleFactory $factory, GeneratorInterface $generator) {
            return [
                'title' => $generator->jobTitle(),
                'body' => $generator->realText(100),
            ];
        }, $n)->withTitle($title)->persist();
        foreach ($articles as $article) {
            $this->assertEquals($title, $article->title);
        }
    }

    public function testPatchDataAndDefaultValue(): void
    {
        $title = 'Some title';
        $article = ArticleFactory::make()->patchData(compact('title'))->persist();
        $this->assertSame($title, $article->title);
    }

    public function testPatchDataAndStaticValue(): void
    {
        $title = 'Some title';
        $article = ArticleFactory::make(['title' => 'Some other title'])->patchData(compact('title'))->persist();
        $this->assertSame($title, $article->title);
    }

    public function testTitleModifiedInMultipleCreationWithCallback(): void
    {
        $n = 3;
        $articles = ArticleFactory::makeWith(function (ArticleFactory $factory, GeneratorInterface $generator) {
            return [
                'body' => $generator->realText(100),
            ];
        })->times($n)->persist();
        $firstTitle = $articles[0]->title;
        $firstBody = $articles[0]->body;
        unset($articles[0]);
        foreach ($articles as $article) {
            $this->assertNotEquals($firstTitle, $article->title);
            $this->assertNotEquals($firstBody, $article->body);
        }
    }

    public function testDefaultValuesOfArticleDifferent(): void
    {
        $n = 5;
        $articles = ArticleFactory::make()->times($n)->getEntities();
        $titles = Hash::extract($articles, '{n}.title');
        $this->assertEquals($n, count(array_unique($titles)));
    }

    /**
     * When creating multiples Authors for an article,
     * these authors should be different
     */
    public function testDefautlValuesOfArticleAuthorsDifferent(): void
    {
        $n = 5;
        $article = ArticleFactory::make()->withAuthors($n)->getEntity();
        $authorNames = Hash::extract($article, 'authors.{n}.name');
        $this->assertEquals($n, count(array_unique($authorNames)));
    }
}
