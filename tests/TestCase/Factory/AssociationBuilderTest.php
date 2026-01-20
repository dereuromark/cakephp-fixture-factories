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
use Cake\ORM\Association;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\AssociationBuilderException;
use CakephpFixtureFactories\Factory\AssociationBuilder;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

class AssociationBuilderTest extends TestCase
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

    public function testCheckAssociationWithCorrectAssociation(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $this->assertInstanceOf(
            Association::class,
            $AssociationBuilder->getAssociation('Address'),
        );
        $this->assertInstanceOf(
            Association::class,
            $AssociationBuilder->getAssociation('Address.City.Countries'),
        );
    }

    public function testCheckAssociationWithIncorrectAssociation(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getAssociation('Address.Country');
    }

    public function testGetFactoryFromTableName(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $street = 'Foo';
        /** @var \CakephpFixtureFactories\Test\Factory\AddressFactory $factory */
        $factory = $AssociationBuilder->getFactoryFromTableName('Address', compact('street'));
        $this->assertInstanceOf(AddressFactory::class, $factory);

        $address = $factory->persist();
        $this->assertSame($street, $address->street);
        $this->assertSame(1, AddressFactory::count());
    }

    public function testGetFactoryFromTableNameWrong(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getFactoryFromTableName('Address.UnknownAssociation');
    }

    public function testGetAssociatedFactoryWithNoDepth(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $factory = $AssociationBuilder->getAssociatedFactory('Address');
        $this->assertInstanceOf(AddressFactory::class, $factory);
    }

    public function testGetAssociatedFactoryInPlugin(): void
    {
        $AssociationBuilder = new AssociationBuilder(ArticleFactory::make());

        $amount = 123;
        /** @var \CakephpFixtureFactories\Test\Factory\BillFactory $factory */
        $factory = $AssociationBuilder->getAssociatedFactory('Bills', compact('amount'));
        $this->assertInstanceOf(BillFactory::class, $factory);

        $bill = $factory->persist();
        $this->assertEquals($amount, $bill->amount);
        $this->assertSame(1, BillFactory::count());
    }

    public function testValidateToOneAssociationPass(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $this->assertTrue(
            $AssociationBuilder->validateToOneAssociation('Articles', ArticleFactory::make(2)),
        );
    }

    public function testValidateToOneAssociationFail(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->validateToOneAssociation('Address', AddressFactory::make(2));
    }

    public function testRemoveBrackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $string = 'Authors[10].Address.City[10]';
        $expected = 'Authors.Address.City';

        $this->assertSame($expected, $AssociationBuilder->removeBrackets($string));
    }

    public function testGetTimeBetweenBracketsWithoutBrackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $this->assertNull($AssociationBuilder->getTimeBetweenBrackets('Authors'));
    }

    public function testGetTimeBetweenBracketsWith1Brackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $n = 10;
        $this->assertSame($n, $AssociationBuilder->getTimeBetweenBrackets("Authors[$n]"));
    }

    public function testGetTimeBetweenBracketsWithEmptyBrackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());

        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getTimeBetweenBrackets('Authors[]');
    }

    public function testGetTimeBetweenBracketsWith2Brackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());
        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getTimeBetweenBrackets('Authors[1][2]');
    }

    public function testGetAssociatedFactory(): void
    {
        $AssociationBuilder = new AssociationBuilder(CityFactory::make());
        $factory = CountryFactory::make();
        $AssociationBuilder->addAssociation('Country', $factory);
        $expected = [
            'Country' => $factory->getMarshallerOptions(),
        ];
        $this->assertSame($expected, $AssociationBuilder->getAssociated());
    }

    public function testGetAssociatedFactoryDeep2(): void
    {
        $AddressFactory = AddressFactory::make()->with(
            'City',
            CityFactory::make()->withCountries(),
        );

        $expected = [
            'City' => CityFactory::make()->getMarshallerOptions() + [
                'associated' => [
                    'Countries' => CountryFactory::make()->getMarshallerOptions(),
                ],
            ],
        ];
        $this->assertSame($expected, $AddressFactory->getAssociated());
    }

    public function testGetAssociatedFactoryDeep3(): void
    {
        $AddressFactory = AddressFactory::make()->with(
            'City',
            CityFactory::make()->with(
                'Countries',
                CountryFactory::make()->with('Cities'),
            ),
        );

        $expected = [
            'City' => [
                'validate' => false,
                'forceNew' => true,
                'accessibleFields' => ['*' => true],
                'associated' => [
                    'Countries' => [
                        'validate' => false,
                        'forceNew' => true,
                        'accessibleFields' => ['*' => true],
                        'associated' => [
                            'Cities' => [
                                'validate' => false,
                                'forceNew' => true,
                                'accessibleFields' => ['*' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expected, $AddressFactory->getAssociated());
    }

    public function testDropAssociation(): void
    {
        $AssociationBuilder = new AssociationBuilder(AddressFactory::make());
        $AssociationBuilder->addAssociation('City', CityFactory::make());
        $AssociationBuilder->dropAssociation('City');
        $this->assertEmpty($AssociationBuilder->getAssociated());
    }

    public function testDropAssociationSingular(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::make());
        $AssociationBuilder->addAssociation('Authors', AuthorFactory::make());
        $AssociationBuilder->dropAssociation('Author');
        $this->assertArrayHasKey('Authors', $AssociationBuilder->getAssociated());
    }

    public function testDropAssociationDeep2(): void
    {
        $AssociationBuilder = new AssociationBuilder(AddressFactory::make());
        $AssociationBuilder->addAssociation('City', CityFactory::make()->with('Countries'));
        $AssociationBuilder->dropAssociation('City.Countries');
        $associatedFactory = $AssociationBuilder->getAssociated();
        $this->assertSame(1, count($associatedFactory));
        $this->assertArrayNotHasKey('associated', $associatedFactory);
    }

    public function testGetAssociatedFactoryWithoutAssociation(): void
    {
        $AddressFactory = AddressFactory::make()->without('City');

        $this->assertEmpty($AddressFactory->getAssociated());
    }

    public function testGetAssociatedFactoryWithoutAssociationDeep2(): void
    {
        $AddressFactory = AddressFactory::make()->without('City.Countries');

        $this->assertSame(
            [
                'City' => [
                    'validate' => false,
                    'forceNew' => true,
                    'accessibleFields' => ['*' => true],
                ],
            ],
            $AddressFactory->getAssociated(),
        );
    }

    public function testGetAssociatedFactoryWithBrackets(): void
    {
        $CityFactory = CityFactory::make()->with('Addresses[5]');

        $expected = [
            'Countries' => [
                'validate' => false,
                'forceNew' => true,
                'accessibleFields' => ['*' => true],
            ],
            'Addresses' => [
                'validate' => false,
                'forceNew' => true,
                'accessibleFields' => ['*' => true],
            ],
        ];
        $this->assertSame($expected, $CityFactory->getAssociated());
    }

    public function testGetAssociatedFactoryWithAliasedAssociation(): void
    {
        $ArticleFactory = ArticleFactory::make()
            ->with('ExclusivePremiumAuthors')
            ->without('Authors');

        $this->assertSame([
            'ExclusivePremiumAuthors' => [
                'validate' => false,
                'forceNew' => true,
                'accessibleFields' => ['*' => true],
                'associated' => [
                    'Address' => [
                        'validate' => false,
                        'forceNew' => true,
                        'accessibleFields' => ['*' => true],
                        'associated' => [
                            'City' => [
                                'validate' => false,
                                'forceNew' => true,
                                'accessibleFields' => ['*' => true],
                                'associated' => [
                                    'Countries' => [
                                        'validate' => false,
                                        'forceNew' => true,
                                        'accessibleFields' => ['*' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], $ArticleFactory->getAssociated());
    }

    /**
     * The city associated to that primary country should belong to
     * the primary country
     */
    public function testRemoveAssociatedAssociationForToOneFactory(): void
    {
        $cityName = 'Foo';
        $CountryFactory = CountryFactory::make()->with(
            'Cities',
            CityFactory::make(['name' => $cityName])->withCountries(),
        );

        $this->assertSame([
            'Cities' => [
                'validate' => false,
                'forceNew' => true,
                'accessibleFields' => ['*' => true],
            ],
        ], $CountryFactory->getAssociated());

        $country = $CountryFactory->persist();

        $country = CountryFactory::find()->where(['id' => $country->id])->contain('Cities')->firstOrFail();

        $this->assertSame($cityName, $country->get('cities')[0]->name);
    }
}
