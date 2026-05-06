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

use Cake\Datasource\EntityInterface;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Cake\Utility\Inflector;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\CustomerFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Model\Entity\Address;
use TestApp\Model\Entity\Article;
use TestApp\Model\Entity\Author;
use TestApp\Model\Entity\City;
use TestApp\Model\Entity\Country;
use TestApp\Model\Table\ArticlesTable;
use TestApp\Model\Table\CountriesTable;
use TestPlugin\Model\Entity\Bill;
use function count;
use function is_array;
use function is_int;

class BaseFactoryTest extends TestCase
{
    use TruncateDirtyTables;

    /**
     * @return array<array<\CakephpFixtureFactories\Factory\BaseFactory>>
     */
    public static function dataForTestConnectionInDataProvider(): array
    {
        return [
            [AuthorFactory::new()],
            [BillFactory::new()],
        ];
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory $factory
     */
    #[DataProvider('dataForTestConnectionInDataProvider')]
    public function testConnectionInDataProvider(BaseFactory $factory): void
    {
        $connectionName = $factory->getTable()->getConnection()->configName();
        $this->assertSame('test', $connectionName);
    }

    public function testGetEntityWithArray(): void
    {
        $title = 'blah';
        $entity = ArticleFactory::new()->withTitle($title)->build();
        $this->assertTrue($entity instanceof EntityInterface);
        $this->assertTrue($entity instanceof Article);
        $this->assertSame($title, $entity->title);
    }

    public function testGetEntityWithCallbackReturningArray(): void
    {
        $title = 'blah';
        $entity = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) use ($title) {
            return compact('title');
        })->build();

        $this->assertTrue($entity instanceof EntityInterface);
        $this->assertTrue($entity instanceof Article);
        $this->assertSame($title, $entity->title);
    }

    public function testGetEntitiesWithArray(): void
    {
        $n = 3;
        $entities = ArticleFactory::new(['title' => 'blah'], $n)->buildMany();

        $this->assertSame($n, count($entities));
        foreach ($entities as $entity) {
            $this->assertInstanceOf(EntityInterface::class, $entity);
            $this->assertInstanceOf(Article::class, $entity);
            $this->assertSame('blah', $entity->title);
        }
    }

    public function testGetEntitiesWithCallbackReturningArray(): void
    {
        $n = 3;
        $entities = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
            return [
                'title' => $generator->word(),
            ];
        }, $n)->buildMany();

        $this->assertSame($n, count($entities));
        foreach ($entities as $entity) {
            $this->assertInstanceOf(EntityInterface::class, $entity);
            $this->assertInstanceOf(Article::class, $entity);
        }
    }

    /**
     * Regression: ::new() with count <= 0 used to construct a factory whose
     * downstream build() / save() would throw a cryptic "expected 1, got 0"
     * later. Reject up-front with a clear message.
     */
    public function testNewRejectsNonPositiveCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('::new() expects a positive count');

        ArticleFactory::new(0);
    }

    public function testNewRejectsNegativeCountSecondArg(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('::new() expects a positive count');

        ArticleFactory::new([], -2);
    }

    public function testCountRejectsNonPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('::count() expects a positive integer');

        ArticleFactory::new()->count(0);
    }

    /**
     * Regression: collectArrayFromCallable() previously invoked the callable
     * once at collection time (just to type-check its return value) and a
     * second time per entity at compile time. Closures with side effects (a
     * counter, a sequence, an external generator step) fired one extra time.
     * The callable must be invoked exactly once per built entity.
     */
    public function testCallbackIsInvokedExactlyOncePerEntity(): void
    {
        $invocations = 0;
        $n = 4;
        $entities = ArticleFactory::new(function () use (&$invocations) {
            $invocations++;

            return ['title' => 'count-' . $invocations];
        }, $n)->buildMany();

        $this->assertCount($n, $entities);
        $this->assertSame($n, $invocations, 'callable must run exactly once per built entity');
        $this->assertSame('count-1', $entities[0]->title);
        $this->assertSame('count-' . $n, $entities[$n - 1]->title);
    }

    public function testGetTable(): void
    {
        $table = ArticleFactory::new()->getTable();
        $this->assertInstanceOf(ArticlesTable::class, $table);
    }

    /**
     * Given : EntitiesTable has association belongsTo 'EntityType' to table Options
     * When : Calling EntityFactory withOne OptionFactory
     *         And calling persist
     * Then : The returned root entity should be of type Entity
     *         And the entity stored in entity_type should be of type Option
     *         And the root entity's foreign key should be an int
     *         And the root entity id key should be an int
     */
    public function testWithOnePersistOneLevel(): void
    {
        $author = AuthorFactory::new(['name' => 'test author'])
            ->with('Address', AddressFactory::new(['street' => 'test street']))
            ->save();

        $this->assertTrue($author instanceof Author);
        $this->assertTrue(is_int($author->id));
        $this->assertTrue($author->address instanceof Address);
        $this->assertSame($author->address_id, $author->address->id);
    }

    public function testMakeMultipleWithArray(): void
    {
        $n = 3;
        $entities = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
            return [
                'title' => $generator->sentence(),
            ];
        }, $n)->saveMany();

        $this->assertSame($n, count($entities));
        $previousName = '';
        foreach ($entities as $entity) {
            $this->assertTrue($entity instanceof Article);
            $this->assertNotSame($previousName, $entity->title);
            $previousName = $entity->title;
        }
    }

    public function testMakeFromArrayMultiple(): void
    {
        $n = 3;
        $entities = ArticleFactory::new([
            'title' => 'test title',
        ], $n)->saveMany();

        $this->assertSame($n, count($entities));
        $previousName = '';
        foreach ($entities as $entity) {
            $this->assertTrue($entity instanceof Article);
            $previousName = $entity->title;
            $this->assertSame($previousName, $entity->title);
        }
    }

    public function testSequenceCyclesThroughStates(): void
    {
        $articles = ArticleFactory::new()
            ->count(5)
            ->sequence(
                ['title' => 'First'],
                ['title' => 'Second'],
            )
            ->buildMany();

        $this->assertSame('First', $articles[0]->title);
        $this->assertSame('Second', $articles[1]->title);
        $this->assertSame('First', $articles[2]->title);
        $this->assertSame('Second', $articles[3]->title);
        $this->assertSame('First', $articles[4]->title);
    }

    public function testSequenceRunsBeforePlainStateOverrides(): void
    {
        $articles = ArticleFactory::new()
            ->count(3)
            ->sequence(
                ['title' => 'First'],
                ['title' => 'Second'],
            )
            ->state(['title' => 'Override'])
            ->buildMany();

        foreach ($articles as $article) {
            $this->assertSame('Override', $article->title);
        }
    }

    public function testAfterBuildRunsBeforeSave(): void
    {
        $article = ArticleFactory::new()
            ->afterBuild(static function (Article $article): void {
                $article->set('title', 'After build');
            })
            ->save();

        $this->assertSame('After build', $article->title);
        $this->assertSame('After build', ArticleFactory::table()->get($article->id)->title);
    }

    public function testAfterSaveRunsAfterPersistence(): void
    {
        $article = ArticleFactory::new()
            ->afterSave(static function (Article $article): void {
                $article->set('title', 'After save');
            })
            ->save();

        $this->assertSame('After save', $article->title);
        $this->assertNotSame('After save', ArticleFactory::table()->get($article->id)->title);
    }

    public function testMakeFromArrayMultipleWithMakeFromArray(): void
    {
        $n = 3;
        $m = 2;
        $articles = ArticleFactory::new([
            'title' => 'test title',
        ], $n)
            ->withAuthors([
                'name' => 'blah',
            ], $m)
            ->saveMany();

        $this->assertSame($n, count($articles));

        foreach ($articles as $article) {
            $this->assertInstanceOf(Article::class, $article);
            $this->assertTrue(is_int($article->id));
            $this->assertSame($m, count($article->authors));
            foreach ($article->authors as $author) {
                $this->assertInstanceOf(Author::class, $author);
                $this->assertTrue(is_int($author->id));
            }
        }
    }

    public function testMakeFromArrayMultipleWithMakeFromCallable(): void
    {
        $n = 3;
        $m = 2;
        $articles = ArticleFactory::new([
            'title' => 'test title',
        ], $n)
            ->withAuthors(function (AuthorFactory $factory, GeneratorInterface $generator) {
                return [
                    'name' => $generator->lastName(),
                ];
            }, $m)
            ->saveMany();

        $this->assertSame($n, count($articles));

        foreach ($articles as $article) {
            $this->assertInstanceOf(Article::class, $article);
            $this->assertTrue(is_int($article->id));
            $this->assertSame($m, count($article->authors));
            foreach ($article->authors as $author) {
                $this->assertInstanceOf(Author::class, $author);
                $this->assertTrue(is_int($author->id));
            }
        }
    }

    public function testMakeSingleWithArray(): void
    {
        $article = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
            return [
                'title' => $generator->sentence(),
            ];
        })->save();

        $this->assertTrue($article instanceof Article);
    }

    public function testMakeSingleWithArrayWithSubFactory(): void
    {
        $city = CityFactory::new(function (CityFactory $factory, GeneratorInterface $generator) {
            $factory->withCountries(function (CountryFactory $factory, GeneratorInterface $generator) {
                return [
                    'name' => $generator->country(),
                ];
            });

            return [
                'name' => $generator->city(),
            ];
        })->save();

        $this->assertTrue($city instanceof City);
        $this->assertTrue(is_int($city->id));
        $this->assertTrue($city->country instanceof Country);
        $this->assertSame($city->country_id, $city->country->id);
    }

    public function testMakeMultipleWithArrayWithSubFactory(): void
    {
        $n = 3;
        $m = 2;
        $articles = ArticleFactory::new(
            function (ArticleFactory $factory, GeneratorInterface $generator) {
                return ['title' => $generator->sentence()];
            },
            $n,
        )
        ->withAuthors(
            function (AuthorFactory $factory, GeneratorInterface $generator) {
                return ['name' => $generator->lastName()];
            },
            $m,
        )
        ->saveMany();

        foreach ($articles as $article) {
            $this->assertTrue($article instanceof Article);
            $this->assertTrue(is_int($article->id));
            $this->assertSame($m, count($article->authors));
            foreach ($article->authors as $author) {
                $this->assertInstanceOf(Author::class, $author);
                $this->assertTrue(is_int($author->id));
            }
        }
    }

    public function testPersistFourlevels(): void
    {
        $n = 3;
        $m = 2;
        $articles = ArticleFactory::new(['title' => 'test title'], $n)
            ->withAuthors(function (AuthorFactory $factory, GeneratorInterface $generator) {
                $factory->withAddress(function (AddressFactory $factory, GeneratorInterface $generator) {
                    $factory->withCity(function (CityFactory $factory, GeneratorInterface $generator) {
                        $factory->withCountries(function (CountryFactory $factory, GeneratorInterface $generator) {
                            return [
                                'name' => $generator->country(),
                            ];
                        });

                        return [
                            'name' => $generator->city(),
                        ];
                    });

                    return [
                        'street' => $generator->streetName(),
                    ];
                });

                return [
                    'name' => $generator->lastName(),
                ];
            }, $m)
            ->saveMany();

        $this->assertSame($n, count($articles));

        foreach ($articles as $article) {
            $this->assertInstanceOf(Article::class, $article);
            $this->assertSame($m, count($article->authors));
            foreach ($article->authors as $author) {
                $this->assertInstanceOf(Author::class, $author);
                $this->assertTrue(is_int($author->id));
                $this->assertSame($author->address_id, $author->address->id);
                $this->assertSame($author->address->city_id, $author->address->city->id);
                $this->assertSame($author->address->city->country_id, $author->address->city->country_id);
            }
        }
    }

    public function testWithOneGetEntityTwoLevel(): void
    {
        $author = AuthorFactory::new(['name' => 'test author'])
            ->withAddress(function (AddressFactory $factory, GeneratorInterface $generator) {
                $factory->withCity(function (CityFactory $factory, GeneratorInterface $generator) {
                    $factory->withCountries(['name' => 'Wonderland']);

                    return [
                        'name' => $generator->city(),
                    ];
                });

                return [
                    'street' => $generator->streetName(),
                ];
            })
            ->build();

        $this->assertTrue($author->address instanceof Address);
        $this->assertTrue($author->address->city instanceof City);
        $this->assertTrue($author->address->city->country instanceof Country);
    }

    /**
     * Given : The AuthorsTable has an association of type 'belongsTo' to table AddressesTable called 'BusinessAddress'
     * When : Making an author with a 'business address' key containing a name
     *         And without setting the associated data manually by calling mergeAssociated
     * Then : When calling persist, the returned entity should be of type Author
     *         And the returned entity should have an id because it was persisted
     *         And the address key should contain an array
     *         And that object should NOT have an id because it was NOT persisted
     */
    public function testMakeHasOneAssociationFromArrayWithoutSettingAssociatedThenPersist(): void
    {
        $persistedEntity = AuthorFactory::new([
            'name' => 'test author',
            'business_address' => [
                'street' => 'test address',
            ],
        ])->save();

        $this->assertTrue($persistedEntity instanceof Author);
        $this->assertTrue(is_int($persistedEntity->id));
        $this->assertTrue(is_array($persistedEntity->business_address));
        $this->assertFalse(isset($persistedEntity->business_address->id));
    }

    /**
     * Given : The AuthorsTable has an association of type 'hasOne' to table AddressesTable called 'Address'
     * When : Making an author with an 'address' key containing a name
     *         And without setting the associated data manually by calling mergeAssociated
     * Then : When calling getEntity, the returned entity should be of type Author
     *         And the returned entity should not have an id because it was not persisted
     *         And the address key should contain an array
     *         And that object should NOT have an id because it was NOT persisted
     */
    public function testMakeHasOneAssociationFromArrayWithoutSettingAssociatedThenGetEntity(): void
    {
        $factory = AuthorFactory::new([
            'name' => 'test project',
            'business_address' => [
                'name' => 'test project address',
            ],
        ]);

        $marshalledEntity = $factory->build();

        $this->assertTrue($marshalledEntity instanceof Author);
        $this->assertFalse(isset($marshalledEntity->id));
        $this->assertTrue(is_array($marshalledEntity->business_address));
    }

    /**
     * Given : The AuthorsTable has an association of type 'hasOne' to table AddressesTable called 'Address'
     * When : Making an author with an 'address' key containing a name
     *         And setting the associated data manually by calling mergeAssociated
     * Then : When calling persist,
     *             the returned entity should be of type Author
     *         And the returned entity should have an id because it was persisted
     *         And the address key should contain an Entity of type Address
     *         And that object should have an id because it was persisted
     */
    public function testMakeHasOneAssociationFromArrayWithSettingAssociatedThenPersist(): void
    {
        $factory = AuthorFactory::new([
            'name' => 'test author',
            'business_address' => [
                'street' => 'test address',
                'city_id' => CityFactory::new()->save()->id,
            ],
        ])->mergeAssociated(['BusinessAddress']);

        $persistedEntity = $factory->save();

        $this->assertTrue($persistedEntity instanceof Author);
        $this->assertTrue(is_int($persistedEntity->id));
        $this->assertTrue($persistedEntity->business_address instanceof Address);
        $this->assertTrue(is_int($persistedEntity->business_address->id));
    }

    /**
     * Given : The AuthorsTable has an association of type 'hasOne' to table AddressesTable called 'Address'
     * When : Making a project with an 'address' key containing a name
     *         And setting the associated data manually by calling mergeAssociated
     * Then : When calling getEntity,
     *             the returned entity should be of type Author
     *         And the returned entity should NOT have an id because it marshalled
     *         And the address key should contain an Entity of type Address
     *         And that object should NOT have an id because it was marshalled
     */
    public function testMakeHasOneAssociationFromArrayWithSettingAssociatedThenGetEntity(): void
    {
        $factory = AuthorFactory::new([
            'name' => 'test author',
            'business_address' => [
                'street' => 'test street',
            ],
        ])->mergeAssociated(['BusinessAddress']);

        $marshalledEntity = $factory->build();

        $this->assertTrue($marshalledEntity instanceof Author);
        $this->assertFalse(isset($marshalledEntity->id));
        $this->assertTrue($marshalledEntity->business_address instanceof Address);
        $this->assertFalse(isset($marshalledEntity->business_address->id));
    }

    public function testMakeHasOneAssociationFromCallableThenPersist(): void
    {
        $entity = AuthorFactory::new(function (AuthorFactory $factory, GeneratorInterface $generator) {
            $factory->withAddress(function (AddressFactory $factory, GeneratorInterface $generator) {
                return [
                    'street' => $generator->streetAddress(),
                ];
            });

            return [
                'name' => $generator->lastName(),
            ];
        })->save();

        $this->assertTrue($entity instanceof Author);
        $this->assertTrue(is_int($entity->id));
        $this->assertTrue($entity->address instanceof Address);
        $this->assertTrue(is_int($entity->address->id));
    }

    public function testMakeHasOneAssociationFromCallableWithAssociatedDataInSingleArrayThenPersist(): void
    {
        $author = AuthorFactory::new(function (AuthorFactory $factory, GeneratorInterface $generator) {
            return [
                'name' => $generator->name(),
                'business_address' => [
                    'street' => $generator->streetAddress(),
                ],
            ];
        })->save();

        $this->assertTrue($author instanceof Author);
        $this->assertTrue(is_int($author->id));
        $this->assertFalse($author->business_address instanceof Address);
    }

    public function testMakeHasOneAssociationFromCallableWithAssociatedDataUsingWith(): void
    {
        $entity = AuthorFactory::new(function (AuthorFactory $factory, GeneratorInterface $generator) {
            $factory->with('Address', AddressFactory::new(['street' => $generator->streetAddress()]));

            return ['name' => $generator->lastName()];
        })->save();

        $this->assertTrue($entity instanceof Author);
        $this->assertTrue(is_int($entity->id));
        $this->assertTrue($entity->address instanceof Address);
        $this->assertTrue(is_int($entity->address->id));
    }

    public function testMakeTenHasOneAssociationFromCallableWithAssociatedDataUsingWith(): void
    {
        $n = 10;
        $entities = AuthorFactory::new(function (AuthorFactory $factory, GeneratorInterface $generator) {
            $factory->with('Address', AddressFactory::new(['street' => $generator->streetAddress()]));

            return ['name' => $generator->lastName()];
        }, $n)->saveMany();

        $this->assertSame($n, count($entities));
        foreach ($entities as $entity) {
            $this->assertTrue($entity instanceof Author);
            $this->assertTrue(is_int($entity->id));
            $this->assertTrue($entity->address instanceof Address);
            $this->assertTrue(is_int($entity->address->id));
        }
    }

    public function testGetEntityAfterMakingMultipleShouldReturnTheFirstOfAll(): void
    {
        $title = 'Foo';
        $articles = ArticleFactory::new(compact('title'), 2)->buildMany();
        $this->assertSame($title, $articles[0]->title);
    }

    public function testGetEntityAfterMakingMultipleFromArrayShouldReturnTheFirstOfAll(): void
    {
        $title = 'Foo';
        $articles = ArticleFactory::new([
            ['title' => $title],
            ['title' => 'Bar'],
        ], 2)->buildMany();
        $this->assertSame($title, $articles[0]->title);
    }

    public function testGetEntitiesAfterMakingOneShouldNotThrowException(): void
    {
        $title = 'foo';
        $articles = ArticleFactory::new(compact('title'))->buildMany();
        $this->assertIsArray($articles);
        $this->assertSame($title, $articles[0]->title);
    }

    public function testHasAssociation(): void
    {
        $authorsTable = AuthorFactory::new()->getTable();
        $this->assertTrue($authorsTable->hasAssociation('Address'));
        $this->assertTrue($authorsTable->hasAssociation('Articles'));
    }

    public function testAssociationByPropertyName(): void
    {
        $articlesTable = ArticleFactory::new()->getTable();
        $this->assertTrue($articlesTable->hasAssociation(Inflector::camelize('authors')));
    }

    public function testEvaluateCallableThatReturnsArray(): void
    {
        $callable = function () {
            return [
                'name' => 'blah',
            ];
        };

        $evaluation = $callable();
        $this->assertSame(['name' => 'blah'], $evaluation);
    }

    public function testCreatingFixtureWithPrimaryKey(): void
    {
        $id = 100;

        $article = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) use ($id) {
            return [
                'id' => $id,
                'name' => $generator->sentence(),
            ];
        })->save();

        $this->assertSame($id, $article->id);
        $this->assertSame(1, ArticleFactory::query()->count());
        $this->assertSame($id, ArticleFactory::query()->firstOrFail()->get('id'));
    }

    public function testPatchingWithAssociationPluginToApp(): void
    {
        $title = 'Some title';
        $amount = 10;
        $bill = BillFactory::new(compact('amount'))
            ->withArticle(compact('title'))
            ->build();
        $this->assertEquals($title, $bill->article->title);
        $this->assertEquals($amount, $bill->amount);
    }

    public function testPersistingWithAssociationPluginToApp(): void
    {
        $title = 'Some title';
        $amount = 10;
        $bill = BillFactory::new(compact('amount'))
            ->withArticle(compact('title'))
            ->save();
        $this->assertTrue(is_int($bill->id));
        $this->assertTrue(is_int($bill->article->id));
        $this->assertEquals($title, $bill->article->title);
        $this->assertEquals($amount, $bill->amount);
        $this->assertEquals($bill->article_id, $bill->article->id);
    }

    /**
     * Bills have an article set by default in their factory
     * Therefore the article will not be the same as the
     * articles the Bills belong to. See testPatchingWithAssociationWithDefaultAssociationCorrect
     * for a correct implementation
     */
    public function testPatchingWithAssociationWithDefaultAssociation(): void
    {
        $title = 'Some title';
        $amount = 10;
        $n = 2;
        $article = ArticleFactory::new(compact('title'))
            ->withBills(compact('amount'), $n)
            ->build();
        $this->assertEquals($title, $article->title);
        $this->assertEquals($n, count($article->bills));
        $this->assertTrue(empty($article->bills[0]->article));
    }

    public function testPersistingWithAssociationWithDefaultAssociationWrong(): void
    {
        $title = 'Some title';
        $amount = 10;
        $n = 2;
        $article = ArticleFactory::new(compact('title'))
            ->withBills(compact('amount'), $n)
            ->save();

        $this->assertTrue(is_int($article->id));
        $this->assertSame($n, count($article->bills));
        $this->assertEquals($title, $article->title);
        foreach ($article->bills as $bill) {
            $this->assertEquals($bill->article_id, $article->id);
            $this->assertTrue(empty($bill->article));
            $this->assertEquals($amount, $bill->amount);
            $this->assertInstanceOf(Bill::class, $bill);
        }
    }

    /**
     * The fixture factories stop infinite propagation
     * Bills have an article set by default in their factory
     * However, this redundant association is stopped
     */
    public function testPersistingWithAssociationWithDefaultAssociationUnstopped(): void
    {
        $title = 'Some title';
        $amount = 10;
        $n = 2;
        $article = ArticleFactory::new(compact('title'))
            ->withBillsWithArticle(compact('amount'), $n)
            ->save();

        $this->assertTrue(is_int($article->id));
        $this->assertSame($n, count($article->bills));
        $this->assertEquals($title, $article->title);
        foreach ($article->bills as $bill) {
            $this->assertSame($bill->article_id, $article->id);
            $this->assertEquals($amount, $bill->amount);
            $this->assertInstanceOf(Bill::class, $bill);
            $this->assertNull($bill->article);
        }
    }

    public function testPatchingWithAssociationWithinPlugin(): void
    {
        $name = 'Some name';
        $amount = 10;
        $n = 2;
        $customer = CustomerFactory::new(compact('name'))
            ->withBills(compact('amount'), $n)
            ->build();
        $this->assertEquals($name, $customer->name);
        $this->assertEquals($n, count($customer->bills));
        foreach ($customer->bills as $bill) {
            $this->assertEquals($amount, $bill->amount);
            $this->assertInstanceOf(Bill::class, $bill);
        }
    }

    public function testPersistingWithAssociationWithinPlugin(): void
    {
        $name = 'Some name';
        $amount = 10;
        $n = 2;
        $customer = CustomerFactory::new(compact('name'))
            ->withBills(compact('amount'), $n)
            ->save();

        $this->assertTrue(is_int($customer->id));
        $this->assertSame($n, count($customer->bills));
        $this->assertEquals($name, $customer->name);
        foreach ($customer->bills as $bill) {
            $this->assertEquals($bill->customer_id, $customer->id);
            $this->assertEquals($amount, $bill->amount);
            $this->assertInstanceOf(Bill::class, $bill);
        }
    }

    public function testMultipleWith(): void
    {
        $firstCity = 'First';
        $secondCity = 'Second';
        $thirdCity = 'Third';
        $address = AddressFactory::new()
            ->withCity(['name' => $firstCity])
            ->withCity(['name' => $secondCity])
            ->withCity(['name' => $thirdCity])
            ->save();
        $this->assertEquals(1, CityFactory::query()->count());
        $this->assertEquals($thirdCity, $address->city->name);
    }

    public function testWithoutAssociation(): void
    {
        $article = ArticleFactory::new()->build();
        $this->assertInstanceOf(Author::class, $article->authors[0]);

        $article = ArticleFactory::new()->without('Authors')->build();
        $this->assertNull($article->authors);
    }

    public function testWithoutAssociation2(): void
    {
        $article = ArticleFactory::new()->withBills()->build();
        $this->assertInstanceOf(Bill::class, $article->bills[0]);

        $article = ArticleFactory::new()->withBills()->without('Bills')->build();
        $this->assertNull($article->bills);
    }

    public function testKeepDirtyWithGetEntity(): void
    {
        $article = ArticleFactory::new()->build();
        $this->assertFalse($article->isDirty('title'));

        $dirtyArticle = ArticleFactory::new()->keepDirty()->build();
        $this->assertTrue($dirtyArticle->isDirty('title'));
        $this->assertTrue($dirtyArticle->authors[0]->isDirty('name'));
    }

    public function testKeepDirtyPropagatesToToOneAssociations(): void
    {
        $author = AuthorFactory::new()->keepDirty()->build();

        $this->assertTrue($author->isDirty('name'));
        $this->assertTrue($author->address->isDirty('street'));
        $this->assertTrue($author->address->city->isDirty('name'));
        $this->assertTrue($author->address->city->country->isDirty('name'));
    }

    public function testKeepDirtyPropagatesToToManyAssociations(): void
    {
        $article = ArticleFactory::new()->keepDirty()->build();

        $this->assertTrue($article->authors[0]->isDirty('name'));
        $this->assertTrue($article->authors[0]->address->isDirty('street'));
    }

    public function testKeepDirtyPropagatesToAssociationsAddedAfter(): void
    {
        $article = ArticleFactory::new()->keepDirty()->withBills()->build();

        $this->assertTrue($article->bills[0]->isDirty('amount'));
        $this->assertTrue($article->bills[0]->customer->isDirty('name'));
    }

    public function testKeepDirtyPropagatesToAssociationsAddedBefore(): void
    {
        $factory = ArticleFactory::new()->withBills();
        $factory = $factory->keepDirty();

        $article = $factory->build();

        $this->assertTrue($article->bills[0]->isDirty('amount'));
        $this->assertTrue($article->bills[0]->customer->isDirty('name'));
    }

    public function testKeepDirtyPropagatesToProvidedAssociationFactory(): void
    {
        $authorsFactory = AuthorFactory::new(2);
        $article = ArticleFactory::new()->keepDirty()->with('Authors', $authorsFactory)->build();

        $this->assertTrue($article->authors[0]->isDirty('name'));
        $this->assertTrue($article->authors[1]->isDirty('name'));
    }

    public function testKeepDirtyPreservesMultipleFactoriesForTheSameToManyAssociation(): void
    {
        $article = ArticleFactory::new()
            ->without('Authors')
            ->with('Authors', AuthorFactory::new(['name' => 'First']))
            ->with('Authors', AuthorFactory::new(['name' => 'Second']))
            ->keepDirty()
            ->build();

        $this->assertCount(2, $article->authors);
        $this->assertSame('First', $article->authors[0]->name);
        $this->assertSame('Second', $article->authors[1]->name);
        $this->assertTrue($article->authors[0]->isDirty('name'));
        $this->assertTrue($article->authors[1]->isDirty('name'));
    }

    public function testHandlingOfMultipleIdenticalWith(): void
    {
        AuthorFactory::new()->withAddress()->withAddress()->save();

        $this->assertEquals(1, AddressFactory::query()->count());
    }

    public function testSaveMultipleInArray(): void
    {
        $name1 = 'Foo';
        $name2 = 'Bar';
        $countries = CountryFactory::new([
            ['name' => $name1],
            ['name' => $name2],
        ])->saveMany();

        $this->assertSame(2, CountryFactory::query()->count());
        $this->assertSame($name1, $countries[0]->name);
        $this->assertSame($name2, $countries[1]->name);
    }

    public function testSaveMultipleInArrayWithTimes(): void
    {
        $times = 2;
        $name1 = 'Foo';
        $name2 = 'Bar';
        $countries = CountryFactory::new([
            ['name' => $name1],
            ['name' => $name2],
        ], $times)->saveMany();

        $this->assertSame($times * 2, CountryFactory::query()->count());

        $this->assertSame($name1, $countries[0]->name);
        $this->assertSame($name2, $countries[1]->name);
        $this->assertSame($name1, $countries[2]->name);
        $this->assertSame($name2, $countries[3]->name);
    }

    public function testSaveMultipleHasManyAssociation(): void
    {
        $amount1 = rand(1, 100);
        $amount2 = rand(1, 100);
        $customer = CustomerFactory::new()
            ->withBills([
                ['amount' => $amount1],
                ['amount' => $amount2],
            ])->save();

        $this->assertSame(2, BillFactory::query()->count());
        $this->assertEquals($amount1, $customer->bills[0]->amount);
        $this->assertEquals($amount2, $customer->bills[1]->amount);
    }

    public function testSaveMultipleHasManyAssociationAndTimes(): void
    {
        $times = 2;
        $amount1 = rand(1, 100);
        $amount2 = rand(1, 100);
        $customer = CustomerFactory::new()
            ->withBills([
                ['amount' => $amount1],
                ['amount' => $amount2],
            ], $times)->save();

        $this->assertSame(2 * $times, BillFactory::query()->count());
        $this->assertEquals($amount1, $customer->bills[0]->amount);
        $this->assertEquals($amount2, $customer->bills[1]->amount);
        $this->assertEquals($amount1, $customer->bills[2]->amount);
        $this->assertEquals($amount2, $customer->bills[3]->amount);
    }

    /**
     * @return array
     */
    public static function feedTestSetTimes()
    {
        return [[rand(1, 10)], [rand(1, 10)], [rand(1, 10)]];
    }

    /**
     * @param int $times
     */
    #[DataProvider('feedTestSetTimes')]
    public function testSetTimes(int $times): void
    {
        ArticleFactory::new()->count($times)->saveMany();

        $this->assertSame($times, ArticleFactory::query()->count());
    }

    /**
     * The max length of a country name being set in CountriesTable
     * this test verifies that the validation is triggered on regular marshalling/saving
     * , but is ignored by the factories
     */
    public function testSkipValidation(): void
    {
        $maxLength = CountriesTable::NAME_MAX_LENGTH;
        $CountriesTable = TableRegistry::getTableLocator()->get('Countries');
        $name = str_repeat('a', $maxLength + 1);

        $country = $CountriesTable->newEntity(compact('name'));
        $this->assertTrue($country->hasErrors());
        $this->assertFalse($CountriesTable->save($country));

        $country = CountryFactory::new(compact('name'))->build();
        $this->assertFalse($country->hasErrors());
        $country = CountryFactory::new(compact('name'))->save();
        $this->assertInstanceOf(Country::class, $country);
    }

    public function testMakeEntityWithNumber(): void
    {
        $n = 2;
        $countries = CountryFactory::new($n)->buildMany();
        $this->assertInstanceOf(Country::class, $countries[0]);
    }

    public function testMakeEntitiesWithNumber(): void
    {
        $n = 2;
        $country = CountryFactory::new($n)->buildMany();
        $this->assertSame($n, count($country));
    }

    public function testMakeAliases(): void
    {
        $title = 'Foo';
        $articles = ArticleFactory::new()->count(2)->buildMany();
        $this->assertCount(2, $articles);

        $fromCallable = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
            return ['title' => $generator->jobTitle()];
        })->build();
        $this->assertNotEmpty($fromCallable->title);

        $base = ArticleFactory::new(['title' => $title])->build();
        $wrapped = ArticleFactory::from($base)->build();
        $this->assertSame($base, $wrapped);
    }

    public function testPersistWithNonCreatedSubEntities(): void
    {
        $authors = AuthorFactory::new(5)->buildMany();
        $article = ArticleFactory::new()
            ->withAuthors($authors)
            ->save();

        $this->assertCount(5, $article->authors);
    }
}
