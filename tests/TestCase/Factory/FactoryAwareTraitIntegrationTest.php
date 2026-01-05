<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\FactoryNotFoundException;
use CakephpFixtureFactories\Factory\FactoryAwareTrait;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\PremiumAuthorFactory;
use PHPUnit\Framework\Attributes\DataProvider;

class FactoryAwareTraitIntegrationTest extends TestCase
{
    use FactoryAwareTrait;

    public static function setUpBeforeClass(): void
    {
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
    }

    public static function tearDownAfterClass(): void
    {
        Configure::delete('FixtureFactories.testFixtureNamespace');
    }

    public static function factoryFoundData(): array
    {
        return [
            ['country', CountryFactory::class],
            ['Country', CountryFactory::class],
            ['countries', CountryFactory::class],
            ['Countries', CountryFactory::class],
            ['premiumAuthor', PremiumAuthorFactory::class],
            ['PremiumAuthor', PremiumAuthorFactory::class],
            ['premiumAuthors', PremiumAuthorFactory::class],
            ['PremiumAuthors', PremiumAuthorFactory::class],
        ];
    }

    #[DataProvider('factoryFoundData')]
    public function testGetFactoryFound(string $name, string $expected): void
    {
        $this->assertInstanceOf($expected, $this->getFactory($name));
    }

    public function testGetFactoryNotFound(): void
    {
        $this->expectException(FactoryNotFoundException::class);
        $this->getFactory('Nevermind');
    }

    public function testGetFactoryWithArgs(): void
    {
        $article = $this->getFactory('articles', ['title' => 'Foo'])->getEntity();
        $this->assertEquals('Foo', $article->title);

        $articles = $this->getFactory('articles', 3)->getEntities();
        $this->assertEquals(3, count($articles));

        $articles = $this->getFactory('articles', ['title' => 'Foo'], 3)->getEntities();
        $this->assertEquals(3, count($articles));
        foreach ($articles as $article) {
            $this->assertEquals('Foo', $article->title);
        }
    }
}
