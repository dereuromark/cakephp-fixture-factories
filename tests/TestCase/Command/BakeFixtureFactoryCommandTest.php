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

namespace CakephpFixtureFactories\Test\TestCase\Command;

use Cake\Console\Arguments;
use Cake\Console\Exception\StopException;
use Cake\Core\Configure;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Test\Util\TestCaseWithFixtureBaking;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use TestApp\Model\Entity\Address;
use TestApp\Model\Entity\Article;
use TestApp\Model\Entity\Author;
use TestApp\Model\Entity\City;
use TestApp\Model\Entity\Country;
use TestApp\Test\Factory\AddressFactory;
use TestApp\Test\Factory\ArticleFactory;
use TestApp\Test\Factory\AuthorFactory;
use TestApp\Test\Factory\CityFactory;
use TestApp\Test\Factory\CountryFactory;
use TestPlugin\Model\Entity\Bill;
use TestPlugin\Model\Entity\Customer;
use TestPlugin\Test\Factory\BillFactory;
use TestPlugin\Test\Factory\CustomerFactory;

/**
 * @see BakeFixtureFactoryCommand
 */
class BakeFixtureFactoryCommandTest extends TestCaseWithFixtureBaking
{
    use TruncateDirtyTables;

    /**
     * @var string
     */
    public $testPluginName = 'TestPlugin';

    /**
     * @var array<string>
     */
    public array $appTables = [
        'Addresses',
        'ArticlesAuthors',
        'Articles',
        'Authors',
        'Cities',
        'Countries',
        'PremiumAuthors',
    ];

    /**
     * @var array<string>
     */
    public array $pluginTables = [
        'Bills',
        'Customers',
    ];

    public function testGetFileName(): void
    {
        $name = 'Model';
        $this->assertSame('ModelFactory.php', $this->FactoryCommand->getFactoryFileName($name));
    }

    public function testGetPath(): void
    {
        $args = new Arguments([], [], []);

        $path = $this->FactoryCommand->getPath($args);
        Configure::write('FixtureFactories.testFixtureOutputDir', 'my/custom/path');
        $customPath = $this->FactoryCommand->getPath($args);

        $this->assertStringEndsWith('tests' . DS . 'TestApp' . DS . 'tests' . DS . 'Factory' . DS, $path);
        $this->assertStringEndsWith('tests' . DS . 'TestApp' . DS . 'tests' . DS . 'my' . DS . 'custom' . DS . 'path' . DS, $customPath);
    }

    public function testGetTableListInApp(): void
    {
        $this->assertEquals($this->appTables, array_values($this->FactoryCommand->getTableList($this->io)));
    }

    public function testGetTableListInPlugin(): void
    {
        $this->FactoryCommand->plugin = $this->testPluginName;
        $this->assertEquals($this->pluginTables, array_values($this->FactoryCommand->getTableList($this->io)));
    }

    public function testHandleAssociationsWithArticles(): void
    {
        $associations = $this->FactoryCommand->setTable('Articles', $this->io)->getAssociations();
        $expected = [
            'toOne' => [],
            'oneToMany' => [
                'Bills' => [
                    'fqcn' => 'TestPlugin\Test\Factory\BillFactory',
                    'className' => 'BillFactory',
                ],
                'ArticlesAuthors' => [
                    'fqcn' => 'TestApp\Test\Factory\ArticlesAuthorFactory',
                    'className' => 'ArticlesAuthorFactory',
                ],
            ],
            'manyToMany' => [
                'Authors' => [
                    'fqcn' => 'TestApp\Test\Factory\AuthorFactory',
                    'className' => 'AuthorFactory',
                ],
                'ExclusivePremiumAuthors' => [
                    'fqcn' => 'TestApp\Test\Factory\PremiumAuthorFactory',
                    'className' => 'PremiumAuthorFactory',
                ],
            ],
        ];
        $this->assertEquals($expected, $associations);
    }

    public function testHandleAssociationsWithAuthors(): void
    {
        $associations = $this->FactoryCommand->setTable('Authors', $this->io)->getAssociations();
        $expected = [
            'toOne' => [
                'Address' => [
                    'fqcn' => 'TestApp\Test\Factory\AddressFactory',
                    'className' => 'AddressFactory',
                ],
                'BusinessAddress' => [
                    'fqcn' => 'TestApp\Test\Factory\AddressFactory',
                    'className' => 'AddressFactory',
                ],
            ],
            'oneToMany' => [],
            'manyToMany' => [
                'Articles' => [
                    'fqcn' => 'TestApp\Test\Factory\ArticleFactory',
                    'className' => 'ArticleFactory',
                ],
            ],
        ];
        $this->assertEquals($expected, $associations);
    }

    public function testHandleAssociationsWithAddresses(): void
    {
        $associations = $this->FactoryCommand->setTable('Addresses', $this->io)->getAssociations();
        $expected = [
            'toOne' => [
                'City' => [
                    'fqcn' => 'TestApp\Test\Factory\CityFactory',
                    'className' => 'CityFactory',
                ],
            ],
            'oneToMany' => [
                'Authors' => [
                    'fqcn' => 'TestApp\Test\Factory\AuthorFactory',
                    'className' => 'AuthorFactory',
                ],
            ],
            'manyToMany' => [],
        ];
        $this->assertEquals($expected, $associations);
    }

    public function testHandleAssociationsWithBillsWithoutPlugin(): void
    {
        $associations = $this->FactoryCommand->setTable('Bills', $this->io)->getAssociations();
        $expected = [
            'toOne' => [],
            'oneToMany' => [],
            'manyToMany' => [],
        ];
        $this->assertEquals($expected, $associations);
    }

    public function testHandleAssociationsWithBills(): void
    {
        $this->FactoryCommand->plugin = $this->testPluginName;
        $associations = $this->FactoryCommand->setTable('Bills', $this->io)->getAssociations();

        $expected = [
            'toOne' => [
                'Article' => [
                    'fqcn' => 'TestApp\Test\Factory\ArticleFactory',
                    'className' => 'ArticleFactory',
                ],
                'Customer' => [
                    'fqcn' => 'TestPlugin\Test\Factory\CustomerFactory',
                    'className' => 'CustomerFactory',
                ],
            ],
            'oneToMany' => [],
            'manyToMany' => [],
        ];
        $this->assertEquals($expected, $associations);
    }

    public function testBakeUnexistingTable(): void
    {
        $this->expectException(StopException::class);

        $this->FactoryCommand->setTable('ignore_that', $this->io);
    }

    public function testRunBakeWithNoArguments(): void
    {
        $this->bake();
    }

    public function testRunBakeWithWrongModel(): void
    {
        $this->bake(['model' => 'SomeModel']);
    }

    public function testRunBakeAllWithMethods(): void
    {
        $this->bake([], ['methods' => true, 'all' => true]);

        $title = 'Foo';
        $articleFactory = ArticleFactory::make(compact('title'))->withAuthors([], 2);
        $this->assertInstanceOf(ArticleFactory::class, $articleFactory);

        $article = $articleFactory->getEntity();
        $this->assertEquals($title, $article->title);
        $authors = $article->authors;
        $this->assertSame(2, count($authors));
        foreach ($authors as $author) {
            $this->assertInstanceOf(Author::class, $author);
        }
    }

    public function testRunBakeAllInTestAppWithMethods(): void
    {
        $this->bake([], ['all' => true, 'methods' => true]);

        $this->assertInstanceOf(BaseFactory::class, ArticleFactory::make());
        $this->assertInstanceOf(BaseFactory::class, AddressFactory::make());
        $this->assertInstanceOf(BaseFactory::class, AuthorFactory::make());
        $this->assertInstanceOf(BaseFactory::class, CityFactory::make());
        $this->assertInstanceOf(BaseFactory::class, CountryFactory::make());

        $country = CountryFactory::make(['name' => 'Foo'])->persist();
        unset($country['id']);
        $city = CityFactory::make(['name' => 'Foo'])->withCountry($country->toArray())->persist();
        unset($city['id']);
        $address = AddressFactory::make(['street' => 'Foo'])->withCity($city->toArray())->persist();
        unset($address['id']);
        $author = AuthorFactory::make(['name' => 'Foo'])->withAddress($address->toArray())->persist();
        $article = ArticleFactory::make(['title' => 'Foo'])->withAuthors($author->toArray())->persist();
        $address2 = AddressFactory::make(['street' => 'Foo2'])->withCity($city->toArray())->withAuthors(['name' => 'Foo2'])->persist();

        $this->assertInstanceOf(Article::class, $article);
        $this->assertInstanceOf(Author::class, $author);
        $this->assertInstanceOf(Address::class, $address);
        $this->assertInstanceOf(Address::class, $address2);
        $this->assertInstanceOf(City::class, $city);
        $this->assertInstanceOf(Country::class, $country);
    }

    public function testRunBakeWithModel(): void
    {
        $this->bake(['Articles']);

        $title = 'Foo';
        $articleFactory = ArticleFactory::make(compact('title'));
        $this->assertInstanceOf(BaseFactory::class, $articleFactory);

        $article = $articleFactory->persist();
        $this->assertEquals($title, $article['title']);
    }

    public function testRunBakeAllInTestApp(): void
    {
        $this->bake([], ['all' => true]);

        $this->assertInstanceOf(BaseFactory::class, ArticleFactory::make());
        $this->assertInstanceOf(BaseFactory::class, AddressFactory::make());
        $this->assertInstanceOf(BaseFactory::class, AuthorFactory::make());
        $this->assertInstanceOf(BaseFactory::class, CityFactory::make());
        $this->assertInstanceOf(BaseFactory::class, CountryFactory::make());

        $country = CountryFactory::make(['name' => 'Foo'])->persist();
        unset($country['id']);
        $city = CityFactory::make(['name' => 'Foo'])->with('Country', CountryFactory::make($country->toArray()))->persist();
        unset($city['id']);
        $address = AddressFactory::make(['street' => 'Foo'])->with('City', CityFactory::make($city->toArray()))->persist();
        unset($address['id']);
        $author = AuthorFactory::make(['name' => 'Foo'])->with('Address', AddressFactory::make($address->toArray()))->persist();
        $article = ArticleFactory::make(['title' => 'Foo'])->with('Authors', AuthorFactory::make($author->toArray()))->persist();

        $this->assertInstanceOf(Article::class, $article);
        $this->assertInstanceOf(Author::class, $author);
        $this->assertInstanceOf(Address::class, $address);
        $this->assertInstanceOf(City::class, $city);
        $this->assertInstanceOf(Country::class, $country);
    }

    public function testRunBakeAllInTestPlugin(): void
    {
        $this->bake(['Articles']);

        $this->bake([], ['plugin' => 'TestPlugin', 'all' => true, 'methods' => true]);

        $customer = CustomerFactory::make(['name' => 'Foo'])->persist();
        unset($customer['id']);
        $article = ArticleFactory::make(['title' => 'Foo'])->persist();
        unset($article['id']);

        $bill = BillFactory::make(['amount' => 100])
            ->with('Customer', CustomerFactory::make($customer->toArray()))
            ->with('Article', ArticleFactory::make($article->toArray()))
            ->persist();

        $this->assertInstanceOf(Article::class, $article);
        $this->assertInstanceOf(Bill::class, $bill);
        $this->assertInstanceOf(Customer::class, $customer);
    }

    /**
     * @return array<array<mixed>>
     */
    public static function dataForTestThisTableShouldBeBaked(): array
    {
        return [
            ['Cities', null, true],
            ['Cities', 'TestPlugin', false],
            ['Bills', null, false],
            ['Bills', 'TestPlugin', true],
            ['AbstractApp', null, false],
            ['AbstractPlugin', 'TestPlugin', false],
        ];
    }

    /**
     * @dataProvider dataForTestThisTableShouldBeBaked
     *
     * @param string $model
     * @param mixed $plugin
     * @param bool $expected
     */
    public function testThisTableShouldBeBaked(string $model, $plugin, bool $expected): void
    {
        $this->FactoryCommand->plugin = $plugin;

        $this->assertSame($expected, $this->FactoryCommand->thisTableShouldBeBaked($model, $this->io));
    }

    public function testCommandHasCommonOptions(): void
    {
        $options = $this->FactoryCommand->getOptionParser()->toArray();
        $this->assertArrayHasKey('connection', $options['options']);
    }
}
