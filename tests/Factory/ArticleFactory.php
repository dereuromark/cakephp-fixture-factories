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
namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use TestApp\Model\Entity\Article;

/**
 * Class ArticleFactory
 *
 * @extends BaseFactory<\TestApp\Model\Entity\Article>
 */
class ArticleFactory extends BaseFactory
{
    public const DEFAULT_NUMBER_OF_AUTHORS = 2;

    /**
     * Defines the Table Registry used to generate entities with
     *
     * @return string
     */
    protected function getRootTableRegistryName(): string
    {
        return 'Articles';
    }

    /**
     * Defines the default values of you factory. Useful for
     * not nullable fields.
     * Use the patchData method to set the field values.
     * You may use methods of the factory here
     *
     * @return void
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'title' => $generator->text(120),
        ];
    }

    protected function configure(): static
    {
        return $this->hasAuthors(self::DEFAULT_NUMBER_OF_AUTHORS);
    }

    public function hasAuthors(mixed $n = 1, mixed $parameter = null): self
    {
        if (!is_int($n)) {
            $originalParameter = $n;
            $n = is_int($parameter) ? $parameter : 1;
            $parameter = $originalParameter;
        }

        return $this->has(AuthorFactory::new($parameter)->count($n));
    }

    /**
     * It is important here to stop the propagation of the default template of the bills
     * Otherways, each bills get a new Article, which is not the one produced by the present factory
     *
     * @param mixed $parameter
     * @param int $n
     * @return ArticleFactory
     */
    public function hasBills(mixed $n = 1, mixed $parameter = null): self
    {
        if (!is_int($n)) {
            $originalParameter = $n;
            $n = is_int($parameter) ? $parameter : 1;
            $parameter = $originalParameter;
        }

        return $this->has(BillFactory::new($parameter)->count($n)->without('Article'));
    }

    /**
     * BAD PRACTICE EXAMPLE
     * This method will lead to inconsistencies (see $this->hasBills())
     *
     * @param mixed $parameter
     * @param int $n
     * @return ArticleFactory
     */
    public function hasBillsWithArticle(mixed $n = 1, mixed $parameter = null): self
    {
        if (!is_int($n)) {
            $originalParameter = $n;
            $n = is_int($parameter) ? $parameter : 1;
            $parameter = $originalParameter;
        }

        return $this->has(BillFactory::new($parameter)->count($n));
    }

    /**
     * Set the Article's title
     *
     * @param string $title
     * @return ArticleFactory
     */
    public function withTitle(string $title): self
    {
        return $this->state(compact('title'));
    }

    /**
     * Set the Article's title as a random job title
     *
     * @return ArticleFactory
     */
    public function setJobTitle(): self
    {
        return $this->setField('title', $this->getGenerator()->jobTitle());
    }

    public function withHiddenBiography(string $text): self
    {
        return $this->setField(Article::HIDDEN_PARAGRAPH_PROPERTY_NAME, $text);
    }

    public function published(): self
    {
        return $this->setField('published', true);
    }

    public function unpublished(): self
    {
        return $this->setField('published', 0);
    }
}
