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
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use ReflectionMethod;

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

    public function setUp(): void
    {
        parent::setUp();
        FactoryTableRegistry::getTableLocator()->clear();
    }

    public function tearDown(): void
    {
        FactoryTableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    public function testCheckAssociationWithCorrectAssociation(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

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
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        try {
            $AssociationBuilder->getAssociation('Address.Country');
            $this->fail('Expected AssociationBuilderException to be thrown.');
        } catch (AssociationBuilderException $exception) {
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testGetFactoryFromTableName(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $street = 'Foo';
        /** @var \CakephpFixtureFactories\Test\Factory\AddressFactory $factory */
        $factory = $AssociationBuilder->getFactoryFromTableName('Address', compact('street'));
        $this->assertInstanceOf(AddressFactory::class, $factory);

        $address = $factory->save();
        $this->assertSame($street, $address->street);
        $this->assertSame(1, AddressFactory::query()->count());
    }

    public function testGetFactoryFromTableNameWrong(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        try {
            $AssociationBuilder->getFactoryFromTableName('Address.UnknownAssociation');
            $this->fail('Expected AssociationBuilderException to be thrown.');
        } catch (AssociationBuilderException $exception) {
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function testGetAssociatedFactoryWithNoDepth(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $factory = $AssociationBuilder->getAssociatedFactory('Address');
        $this->assertInstanceOf(AddressFactory::class, $factory);
    }

    public function testGetAssociatedFactoryInPlugin(): void
    {
        $AssociationBuilder = new AssociationBuilder(ArticleFactory::new());

        $amount = 123;
        /** @var \CakephpFixtureFactories\Test\Factory\BillFactory $factory */
        $factory = $AssociationBuilder->getAssociatedFactory('Bills', compact('amount'));
        $this->assertInstanceOf(BillFactory::class, $factory);

        $bill = $factory->save();
        $this->assertEquals($amount, $bill->amount);
        $this->assertSame(1, BillFactory::query()->count());
    }

    public function testValidateToOneAssociationPass(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $this->assertTrue(
            $AssociationBuilder->validateToOneAssociation('Articles', ArticleFactory::new(2)),
        );
    }

    public function testValidateToOneAssociationFail(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->validateToOneAssociation('Address', AddressFactory::new(2));
    }

    public function testRemoveBrackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $string = 'Authors[10].Address.City[10]';
        $expected = 'Authors.Address.City';

        $this->assertSame($expected, $AssociationBuilder->removeBrackets($string));
    }

    public function testGetTimeBetweenBracketsWithoutBrackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $this->assertNull($AssociationBuilder->getTimeBetweenBrackets('Authors'));
    }

    public function testGetTimeBetweenBracketsWith1Brackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $n = 10;
        $this->assertSame($n, $AssociationBuilder->getTimeBetweenBrackets("Authors[$n]"));
    }

    public function testGetTimeBetweenBracketsWithEmptyBrackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());

        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getTimeBetweenBrackets('Authors[]');
    }

    public function testGetTimeBetweenBracketsWith2Brackets(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());
        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getTimeBetweenBrackets('Authors[1][2]');
    }

    public function testGetTimeBetweenBracketsWithZeroThrows(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());
        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getTimeBetweenBrackets('Authors[0]');
    }

    public function testGetTimeBetweenBracketsWithNonNumericThrows(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());
        $this->expectException(AssociationBuilderException::class);
        $AssociationBuilder->getTimeBetweenBrackets('Authors[abc]');
    }

    public function testGetAssociatedFactory(): void
    {
        $AssociationBuilder = new AssociationBuilder(CityFactory::new());
        $factory = CountryFactory::new();
        $AssociationBuilder->addAssociation('Country', $factory);
        $expected = [
            'Country' => $factory->getMarshallerOptions(),
        ];
        $this->assertSame($expected, $AssociationBuilder->getAssociated());
    }

    public function testGetAssociatedFactoryDeep2(): void
    {
        $AddressFactory = AddressFactory::new()->with(
            'City',
            CityFactory::new()->forCountries(),
        );

        $expected = [
            'City' => CityFactory::new()->getMarshallerOptions() + [
                'associated' => [
                    'Countries' => CountryFactory::new()->getMarshallerOptions(),
                ],
            ],
        ];
        $this->assertSame($expected, $AddressFactory->getAssociatedFactories());
    }

    public function testGetAssociatedFactoryDeep3(): void
    {
        $AddressFactory = AddressFactory::new()->with(
            'City',
            CityFactory::new()->with(
                'Countries',
                CountryFactory::new()->with('Cities'),
            ),
        );

        // Note: The deepest Cities doesn't have Countries because the circular
        // reference is prevented by removeAssociationForToOneFactory
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

        $this->assertSame($expected, $AddressFactory->getAssociatedFactories());
    }

    public function testDropAssociation(): void
    {
        $AssociationBuilder = new AssociationBuilder(AddressFactory::new());
        $AssociationBuilder->addAssociation('City', CityFactory::new());
        $AssociationBuilder->dropAssociation('City');
        $this->assertEmpty($AssociationBuilder->getAssociated());
    }

    public function testDropAssociationSingular(): void
    {
        $AssociationBuilder = new AssociationBuilder(AuthorFactory::new());
        $AssociationBuilder->addAssociation('Authors', AuthorFactory::new());
        $AssociationBuilder->dropAssociation('Author');
        $this->assertArrayHasKey('Authors', $AssociationBuilder->getAssociated());
    }

    public function testDropAssociationDeep2(): void
    {
        $AssociationBuilder = new AssociationBuilder(AddressFactory::new());
        $AssociationBuilder->addAssociation('City', CityFactory::new()->with('Countries'));
        $AssociationBuilder->dropAssociation('City.Countries');
        $associatedFactory = $AssociationBuilder->getAssociated();
        $this->assertSame(1, count($associatedFactory));
        $this->assertArrayNotHasKey('associated', $associatedFactory);
    }

    public function testGetAssociatedFactoryWithoutAssociation(): void
    {
        $AddressFactory = AddressFactory::new()->without('City');

        $this->assertEmpty($AddressFactory->getAssociatedFactories());
    }

    public function testGetAssociatedFactoryWithoutAssociationDeep2(): void
    {
        $AddressFactory = AddressFactory::new()->without('City.Countries');

        $this->assertSame(
            [
                'City' => [
                    'validate' => false,
                    'forceNew' => true,
                    'accessibleFields' => ['*' => true],
                ],
            ],
            $AddressFactory->getAssociatedFactories(),
        );
    }

    public function testGetAssociatedFactoryWithBrackets(): void
    {
        $CityFactory = CityFactory::new()->with('Addresses[5]');

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
        $this->assertSame($expected, $CityFactory->getAssociatedFactories());
    }

    public function testGetAssociatedFactoryWithAliasedAssociation(): void
    {
        $ArticleFactory = ArticleFactory::new()
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
        ], $ArticleFactory->getAssociatedFactories());
    }

    public function testPrepareAssociationFactoryMatchesBackAssociationByForeignKey(): void
    {
        $addressFactory = AddressFactory::new();
        if (!$addressFactory->getTable()->hasAssociation('BusinessAuthors')) {
            $addressFactory->getTable()->hasMany('BusinessAuthors', [
                'className' => 'Authors',
                'foreignKey' => 'business_address_id',
            ]);
        }
        $authorFactory = AuthorFactory::new();
        $authorTable = $authorFactory->getTable();
        $authorTable->associations()->remove('BusinessAddress');
        $authorTable->belongsTo('BusinessAddress', [
            'className' => 'Addresses',
            'foreignKey' => 'business_address_id',
        ]);

        $AssociationBuilder = new AssociationBuilder($addressFactory);
        $method = new ReflectionMethod(AssociationBuilder::class, 'findBackAssociation');
        $method->setAccessible(true);

        $backAssociation = $method->invoke(
            $AssociationBuilder,
            $authorTable,
            $addressFactory->getTable()->getAssociation('BusinessAuthors'),
        );

        $this->assertNotNull($backAssociation);
        $this->assertSame('BusinessAddress', $backAssociation->getName());
    }

    public function testManualAssociationsReplaceMarshallerScalarsInsteadOfMergingIntoArrays(): void
    {
        $factory = AddressFactory::new()
            ->with('City')
            ->mergeAssociated(['City' => ['validate' => true]]);

        $associated = $factory->getAssociatedFactories();

        $this->assertTrue($associated['City']['validate']);
        $this->assertIsBool($associated['City']['validate']);
    }

    /**
     * The city associated to that primary country should belong to
     * the primary country. The Countries association on Cities is removed
     * to prevent circular reference (city creating its own country).
     */
    public function testRemoveAssociatedAssociationForToOneFactory(): void
    {
        $cityName = 'Foo';
        $CountryFactory = CountryFactory::new()->with(
            'Cities',
            CityFactory::new(['name' => $cityName])->forCountries(),
        );

        // Countries is removed from Cities to prevent circular reference
        $this->assertSame([
            'Cities' => [
                'validate' => false,
                'forceNew' => true,
                'accessibleFields' => ['*' => true],
            ],
        ], $CountryFactory->getAssociatedFactories());

        $country = $CountryFactory->save();

        $country = CountryFactory::query()->contain('Cities')->where(['Countries.id' => $country->id])->firstOrFail();

        $this->assertSame($cityName, $country->get('cities')[0]->name);
    }
}
