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

use ArrayObject;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Factory\EventCollector;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\CustomerFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Model\Entity\Address;
use TestApp\Model\Entity\Article;
use TestApp\Model\Entity\City;
use TestPlugin\Model\Behavior\SomePluginBehavior;

class EventCollectorTest extends TestCase
{
    use TruncateDirtyTables;

    /**
     * @var \TestApp\Model\Table\CountriesTable
     */
    private $Countries;

    public static function setUpBeforeClass(): void
    {
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
        Configure::write('FixtureFactories.testFixtureGlobalBehaviors', 'SomeBehaviorUsedInMultipleTables');
    }

    public static function tearDownAfterClass(): void
    {
        Configure::delete('FixtureFactories.testFixtureNamespace');
        Configure::delete('FixtureFactories.testFixtureGlobalBehaviors');
    }

    public function setUp(): void
    {
        /** @var \TestApp\Model\Table\CountriesTable $Countries */
        $Countries = TableRegistry::getTableLocator()->get('Countries');
        $this->Countries = $Countries;

        parent::setUp();
    }

    public function tearDown(): void
    {
        Configure::delete('FixtureFactories.testFixtureGlobalBehaviors');
        unset($this->Countries);

        parent::tearDown();
    }

    /**
     * @see EventCollector::setDefaultListeningBehaviors()
     */
    public function testSetDefaultListeningBehaviors(): void
    {
        Configure::write('FixtureFactories.testFixtureGlobalBehaviors', ['Sluggable']);

        $EventManager = new EventCollector('Foo');

        $this->assertSame(
            ['Sluggable', 'Timestamp'],
            $EventManager->getListeningBehaviors(),
        );
    }

    public function testSetBehaviorEmpty(): void
    {
        $EventManager = new EventCollector('Foo');

        $expected = [
            'SomeBehaviorUsedInMultipleTables',
            'Timestamp',
        ];
        $this->assertSame(
            $expected,
            $EventManager->getListeningBehaviors(),
        );
    }

    public static function provideFactories(): array
    {
        return [
            [ArticleFactory::new()],
            [AuthorFactory::new()],
            [AddressFactory::new()],
            [CityFactory::new()],
            [CountryFactory::new()],
            [BillFactory::new()],
            [CustomerFactory::new()],
        ];
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory $factory
     */
    #[DataProvider('provideFactories')]
    public function testTimestamp(BaseFactory $factory): void
    {
        $entity = $factory->save();
        $this->assertNotNull($entity->get('created'));
    }

    public static function runSeveralTimesWithOrWithoutEvents(): array
    {
        return [
            [true], [false], [true], [false],
        ];
    }

    /**
     * @param bool $applyEvent Bind the event once to the model
     */
    #[DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreBeforeMarshalSetOnTheFly(bool $applyEvent): void
    {
        $name = 'Foo';

        $this->Countries->getEventManager()->on('Model.beforeMarshal', function (EventInterface $event, ArrayObject $entity) use ($applyEvent) {
            $entity['eventApplied'] = $applyEvent;
        });

        // Event should apply
        $country = $this->Countries->newEntity(compact('name'));
        $this->assertSame($applyEvent, $country->get('eventApplied'));

        $factory = CountryFactory::new();
        $factory->getTable()->getEventManager()->on('Model.beforeMarshal', function (EventInterface $event, ArrayObject $entity) use ($applyEvent) {
            $entity['eventApplied'] = $applyEvent;
        });
        $country = $factory->build();
        $this->assertSame($applyEvent, $country->get('eventApplied'));
        FactoryTableRegistry::getTableLocator()->clear();

        // Event should be skipped
        $country = CountryFactory::new()->build();
        $this->assertNull($country->get('eventApplied'));

        $country = CountryFactory::new()->listeningToModelEvents('Model.beforeMarshal')->build();
        $this->assertNull($country->get('eventApplied'));
    }

    #[DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreBeforeMarshalSetInTable(bool $applyEvent): void
    {
        $name = 'Foo';

        // Event should be skipped
        $country = CountryFactory::new()->build();
        $this->assertNull($country->get('beforeMarshalTriggered'));

        // Event should apply
        $country = $this->Countries->newEntity(compact('name'));
        $this->assertTrue($country->get('beforeMarshalTriggered'));

        $country = CountryFactory::new()->listeningToModelEvents('Model.beforeMarshal')->build();
        $this->assertTrue($country->get('beforeMarshalTriggered'));
    }

    #[DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreEventInBehaviors(bool $applyEvent): void
    {
        $title = 'This Article';
        $slug = 'This-Article';

        $article = ArticleFactory::new(compact('title'))->save();
        $this->assertEquals(null, $article->get('slug'));

        $article = ArticleFactory::new(compact('title'))->listeningToBehaviors('Sluggable')->save();
        $this->assertEquals($slug, $article->get('slug'));
    }

    public function testSetBehaviorOnTheFly(): void
    {
        $behavior = 'Foo';
        $EventManager = new EventCollector('Bar');
        $EventManager->listeningToBehaviors(['Foo']);

        $expected = [
            'SomeBehaviorUsedInMultipleTables',
            'Timestamp',
            $behavior,
        ];
        $this->assertSame(
            $expected,
            $EventManager->getListeningBehaviors(),
        );
    }

    public function testGetEntityOnNonExistentBehavior(): void
    {
        $behavior = 'Foo';
        $article = ArticleFactory::new()->listeningToBehaviors($behavior)->build();
        $this->assertInstanceOf(Article::class, $article);
    }

    #[DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreEventInBehaviorsOnTheFlyWithCountries(bool $applyEvent): void
    {
        $name = 'Some Country';
        $slug = 'Some-Country';

        $country = CountryFactory::new(compact('name'))->save();
        $this->assertNull($country->get('slug'));

        $country = CountryFactory::new(compact('name'))
            ->listeningToBehaviors('Sluggable')
            ->save();
        $this->assertEquals($slug, $country->get('slug'));
    }

    #[DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreEventInPluginBehaviorsOnTheFlyWithCountries(bool $applyEvent): void
    {
        $field = SomePluginBehavior::BEFORE_SAVE_FIELD;

        $this->Countries->addBehavior('TestPlugin.SomePlugin');

        // The behavior should not apply
        $country = CountryFactory::new()->save();
        $this->assertNull($country->get($field));

        // The behavior should apply
        $country = CountryFactory::new()->listeningToBehaviors('SomePlugin')->save();
        $this->assertTrue($country->get($field));

        // The behavior should not apply
        $country = CountryFactory::new()->save();
        $this->assertNull($country->get($field));

        // The behavior should apply
        Configure::write('FixtureFactories.testFixtureGlobalBehaviors', ['SomePlugin']);
        $country = CountryFactory::new()->save();
        $this->assertTrue($country->get($field));
    }

    public function testSkipValidation(): void
    {
        $city = CityFactory::new()->without('Countries')->build();
        $this->assertInstanceOf(City::class, $city);
        $this->assertEmpty($city->getErrors());
    }

    public function testSkipValidationInAssociation(): void
    {
        $address = AddressFactory::new()
            ->with('City', CityFactory::new()->without('Countries'))
            ->build();
        $this->assertInstanceOf(Address::class, $address);
        $this->assertInstanceOf(City::class, $address->city);
        $this->assertNull($address->city->country);
        $this->assertEmpty($address->getErrors());
    }

    public function testApplyValidationInAssociation(): void
    {
        $address = AddressFactory::new()
            ->with(
                'City',
                CityFactory::new()
                    ->listeningToModelEvents('Model.beforeMarshal')
                    ->without('Countries'),
            )
            ->build();
        $this->assertInstanceOf(Address::class, $address);
        $this->assertInstanceOf(City::class, $address->city);
        $this->assertNull($address->city->country);
        $this->assertTrue($address->city->get('beforeMarshalTriggered'));
    }

    /**
     * Cities have a rule that always return false
     */
    public function testSkipRules(): void
    {
        $city = CityFactory::new()->save();
        $this->assertInstanceOf(City::class, $city);
        $this->assertEmpty($city->getErrors());
    }

    public function testSkipRuleInAssociation(): void
    {
        $address = AddressFactory::new()->build();
        $this->assertInstanceOf(Address::class, $address);
        $this->assertInstanceOf(City::class, $address->city);
        $this->assertEmpty($address->getErrors());
    }

    public function testBeforeMarshalIsTriggeredInAssociationWhenDefinedInDefaultTemplate(): void
    {
        $bill = BillFactory::new()->build();
        $this->assertTrue($bill->get('beforeMarshalTriggeredPerDefault'));

        $bill = CustomerFactory::new()->withBills()->build()->bills[0];
        $this->assertTrue($bill->get('beforeMarshalTriggeredPerDefault'));
    }

    public function testAfterSaveIsTriggeredInAssociationWhenDefinedInDefaultTemplate(): void
    {
        $bill = BillFactory::new()->save();
        $this->assertTrue($bill->get('afterSaveTriggeredPerDefault'));

        $bill = CustomerFactory::new()->withBills()->save()->bills[0];
        $this->assertTrue($bill->get('afterSaveTriggeredPerDefault'));
    }

    public function testSetConnection(): void
    {
        $factory = ArticleFactory::new();
        $originalConnectionName = $factory->getTable()->getConnection()->configName();

        // Set a different connection and verify it's applied
        // Note: CakePHP prefixes connection names with 'test_' during testing
        $factory = $factory->setConnection('dummy');
        $newConnectionName = $factory->getTable()->getConnection()->configName();

        $this->assertNotSame($originalConnectionName, $newConnectionName);
        $this->assertSame('test_dummy', $newConnectionName);
    }

    public function testSetConnectionChaining(): void
    {
        $article = ArticleFactory::new()
            ->setConnection('dummy')
            ->listeningToBehaviors('Sluggable')
            ->build();

        $this->assertInstanceOf(Article::class, $article);

        // Verify the connection was set correctly
        // Note: CakePHP prefixes connection names with 'test_' during testing
        $factory = ArticleFactory::new()->setConnection('dummy');
        $this->assertSame('test_dummy', $factory->getTable()->getConnection()->configName());
    }

    public function testSetEventManagerWithCustomListener(): void
    {
        $customEventManager = new EventManager();
        $customEventManager->on('Model.beforeMarshal', function (EventInterface $event, ArrayObject $entity) {
            $entity['customEventManagerApplied'] = true;
        });

        $article = ArticleFactory::new()
            ->setEventManager($customEventManager)
            ->listeningToModelEvents('Model.beforeMarshal')
            ->build();

        $this->assertTrue($article->get('customEventManagerApplied'));
    }

    public function testSetEventManagerResetsTableCache(): void
    {
        $factory = ArticleFactory::new();
        $originalEventManager = $factory->getTable()->getEventManager();

        $customEventManager = new EventManager();
        $factory = $factory->setEventManager($customEventManager);

        $this->assertNotSame($originalEventManager, $factory->getTable()->getEventManager());
        $this->assertSame($customEventManager, $factory->getTable()->getEventManager());
    }

    public function testDifferentFactoriesDoNotShareScopedTableWhenEventManagersDiffer(): void
    {
        FactoryTableRegistry::getTableLocator()->clear();

        $firstEventManager = new EventManager();
        $secondEventManager = new EventManager();

        $firstFactory = ArticleFactory::new()->setEventManager($firstEventManager);
        $secondFactory = ArticleFactory::new()->setEventManager($secondEventManager);

        $this->assertNotSame($firstFactory->getTable(), $secondFactory->getTable());
        $this->assertSame($firstEventManager, $firstFactory->getTable()->getEventManager());
        $this->assertSame($secondEventManager, $secondFactory->getTable()->getEventManager());
        $this->assertSame('Articles', $firstFactory->getTable()->getAlias());
        $this->assertSame('Articles', $secondFactory->getTable()->getAlias());
    }
}
