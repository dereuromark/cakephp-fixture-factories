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

namespace CakephpFixtureFactories\Test\TestCase;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use TestApp\Model\Entity\Article;

class DocumentationExamplesTest extends TestCase
{
    public function testArticlesFindPublished(): void
    {
        $articles = ArticleFactory::new(['published' => 1])->count(3)->saveMany();
        ArticleFactory::new(['published' => 0])->count(2)->saveMany();

        $result = ArticleFactory::query()->find('published')->find('list')->toArray();

        $expected = [
            $articles[0]->id => $articles[0]->title,
            $articles[1]->id => $articles[1]->title,
            $articles[2]->id => $articles[2]->title,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testExampleStaticData(): void
    {
        $article = ArticleFactory::new()->build();
        $this->assertInstanceOf(Article::class, $article);

        $articles = ArticleFactory::new()->count(2)->buildMany();
        $previous = '';
        foreach ($articles as $article) {
            $this->assertNotEquals($previous, $article['title']);
            $previous = $article['title'];
        }

        ArticleFactory::new(['title' => 'Foo'])->build();

        $articles = ArticleFactory::new(['title' => 'Foo'])->count(3)->buildMany();
        $this->assertEquals(3, count($articles));
        foreach ($articles as $article) {
            $this->assertEquals('Foo', $article['title']);
        }

        $articles = ArticleFactory::new(['title' => 'Foo'])->count(3)->saveMany();
        $this->assertEquals(3, count($articles));
        foreach ($articles as $article) {
            $this->assertEquals('Foo', $article['title']);
        }
    }

    public function testExampleDynamicData(): void
    {
        $articles = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
            return [
                'title' => $generator->text(100),
            ];
        })->count(3)->saveMany();
        $this->assertEquals(3, count($articles));
        $previousTitle = 'Foo';
        foreach ($articles as $article) {
            $this->assertNotEquals($previousTitle, $article['title']);
            $previousTitle = $article['title'];
        }
    }

    public function testExampleSequenceData(): void
    {
        $articles = ArticleFactory::new()
            ->count(4)
            ->sequence(
                ['title' => 'Draft'],
                ['title' => 'Published'],
            )
            ->buildMany();

        $this->assertSame('Draft', $articles[0]->title);
        $this->assertSame('Published', $articles[1]->title);
        $this->assertSame('Draft', $articles[2]->title);
        $this->assertSame('Published', $articles[3]->title);
    }

    public function testExampleAfterCallbacks(): void
    {
        $article = ArticleFactory::new()
            ->afterBuild(static function (Article $article): void {
                $article->set('title', 'Built title');
            })
            ->afterSave(static function (Article $article): void {
                $article->set('title', 'Saved title');
            })
            ->save();

        $this->assertSame('Saved title', $article->title);
        $this->assertSame('Built title', ArticleFactory::table()->get($article->id)->title);
    }

    public function testExampleChainable(): void
    {
        $articleFactory = ArticleFactory::new(['title' => 'Foo']);
        $articleFoo = $articleFactory->build();

        $articleJobOffer = $articleFactory->setJobTitle()->build();
        $this->assertEquals('Foo', $articleFoo['title']);
        $this->assertNotEquals('Foo', $articleJobOffer['title']);
    }

    public function testExampleChainableWithPersist(): void
    {
        $articleFactory = ArticleFactory::new(['title' => 'Foo']);
        $articleFoo = $articleFactory->save();

        $articleJobOffer = $articleFactory->setJobTitle()->save();
        $this->assertEquals('Foo', $articleFoo['title']);
        $this->assertNotEquals('Foo', $articleJobOffer['title']);
    }

    public function testAssociationsMultiple(): void
    {
        $article = ArticleFactory::new()->with('Authors', AuthorFactory::new()->count(10))->save();
        $this->assertEquals(10, count($article['authors']));
        $previous = '';
        foreach ($article['authors'] as $author) {
            $this->assertNotEquals($previous, $author->name);
            $previous = $author->name;
        }

        $article = ArticleFactory::new()->hasAuthors(10)->save();
        $this->assertEquals(10, count($article['authors']));
        $previous = '';
        foreach ($article['authors'] as $author) {
            $this->assertNotEquals($previous, $author->name);
            $previous = $author->name;
        }
    }

    public function testAssociationsMultipleWithBiography(): void
    {
        $article = ArticleFactory::new()->hasAuthors(10, function (AuthorFactory $factory, GeneratorInterface $generator) {
            return [
                'biography' => $generator->realText(),
            ];
        })->save();
        $this->assertEquals(10, count($article['authors']));
        $lastName = '';
        $lastBio = '';
        foreach ($article['authors'] as $author) {
            $this->assertNotEquals($lastName, $author->name);
            $lastName = $author->name;
            $this->assertNotEquals($lastBio, $author->biography);
            $lastBio = $author->biography;
        }
    }
}
