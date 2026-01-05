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

use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Model\Entity\Article;

class BaseFactoryHiddenPropertiesTest extends TestCase
{
    /**
     * @var string
     */
    public const DUMMY_HIDDEN_PARAGRAPH = 'Foo!';

    /**
     * Assert that the hidden paragraph property in articles is well persisted
     * but remains invisible when toArray is called.
     *
     * @param \TestApp\Model\Entity\Article|array<\TestApp\Model\Entity\Article> $articles
     * @param bool $persisted
     */
    private function assertHiddenParagraphIsVisible($articles, bool $persisted): void
    {
        $articles = is_array($articles) ? $articles : [$articles];
        foreach ($articles as $article) {
            $this->assertSame(self::DUMMY_HIDDEN_PARAGRAPH, $article->get(Article::HIDDEN_PARAGRAPH_PROPERTY_NAME));
            if ($persisted) {
                $article = TableRegistry::getTableLocator()->get('Articles')->find()->where([
                    'id' => $article->get('id'),
                    Article::HIDDEN_PARAGRAPH_PROPERTY_NAME => self::DUMMY_HIDDEN_PARAGRAPH,
                ])->firstOrFail();
                $this->assertSame(self::DUMMY_HIDDEN_PARAGRAPH, $article->get(Article::HIDDEN_PARAGRAPH_PROPERTY_NAME));
                $this->assertNull($article->toArray()[Article::HIDDEN_PARAGRAPH_PROPERTY_NAME] ?? null);
            }
        }
    }

    public static function iterate(): array
    {
        return [
            [1, false],
            [1, true],
            [2, false],
            [2, true],
        ];
    }

    /**
     * @Given a property is hidden
     *
     * @When a factory is persisted
     *
     * @Then the field is accessible and persisted.
     *
     * @param int $n
     * @param bool $persist
     */
    #[DataProvider('iterate')]
    public function testHiddenPropertyInMainBuild(int $n, bool $persist): void
    {
        $factory = ArticleFactory::make()->setTimes($n)->withHiddenBiography(self::DUMMY_HIDDEN_PARAGRAPH);

        if ($n > 1) {
            $articles = $persist ? $factory->persist() : $factory->getEntities();
        } else {
            $articles = $persist ? $factory->persist() : $factory->getEntity();
        }
        $this->assertHiddenParagraphIsVisible($articles, $persist);
    }

    /**
     * @Given a property in a belongs to many association is hidden
     *
     * @When a factory is persisted
     *
     * @Then the field is accessible and persisted.
     *
     * @param int $n
     * @param bool $persist
     */
    #[DataProvider('iterate')]
    public function testHiddenPropertyInBelongsToManyAssociation(int $n, bool $persist): void
    {
        $factory = AuthorFactory::make()->with(
            'Articles',
            ArticleFactory::make()->setTimes($n)->withHiddenBiography(self::DUMMY_HIDDEN_PARAGRAPH),
        );

        $articles = $persist ? $factory->persist()->get('articles') : $factory->getEntity()->get('articles');
        $this->assertHiddenParagraphIsVisible($articles, $persist);
    }

    /**
     * @Given a property in a has many association is hidden
     *
     * @When a factory is persisted
     *
     * @Then the field is accessible and persisted.
     *
     * @param int $n
     * @param bool $persist
     */
    #[DataProvider('iterate')]
    public function testHiddenPropertyInBelongsToAssociation(int $n, bool $persist): void
    {
        $factory = BillFactory::make()->setTimes($n)->with(
            'Article',
            ArticleFactory::make()->withHiddenBiography(self::DUMMY_HIDDEN_PARAGRAPH),
        );

        $bills = $persist ? $factory->persist() : $factory->getEntity();

        if (is_array($bills)) {
            foreach ($bills as $bill) {
                $this->assertHiddenParagraphIsVisible($bill->get('article'), $persist);
            }
        } else {
            $this->assertHiddenParagraphIsVisible($bills->get('article'), $persist);
        }
    }
}
