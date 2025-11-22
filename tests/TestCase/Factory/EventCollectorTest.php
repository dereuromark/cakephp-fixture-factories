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
            [ArticleFactory::make()],
            [AuthorFactory::make()],
            [AddressFactory::make()],
            [CityFactory::make()],
            [CountryFactory::make()],
            [BillFactory::make()],
            [CustomerFactory::make()],
        ];
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory $factory
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideFactories')]
    public function testTimestamp(BaseFactory $factory): void
    {
        $entity = $factory->persist();
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
    #[\PHPUnit\Framework\Attributes\DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreBeforeMarshalSetOnTheFly(bool $applyEvent): void
    {
        $name = 'Foo';

        $this->Countries->getEventManager()->on('Model.beforeMarshal', function (EventInterface $event, ArrayObject $entity) use ($applyEvent) {
            $entity['eventApplied'] = $applyEvent;
        });

        // Event should apply
        $country = $this->Countries->newEntity(compact('name'));
        $this->assertSame($applyEvent, $country->get('eventApplied'));

        $factory = CountryFactory::make();
        $factory->getTable()->getEventManager()->on('Model.beforeMarshal', function (EventInterface $event, ArrayObject $entity) use ($applyEvent) {
            $entity['eventApplied'] = $applyEvent;
        });
        $country = $factory->getEntity();
        $this->assertSame($applyEvent, $country->get('eventApplied'));
        FactoryTableRegistry::getTableLocator()->clear();

        // Event should be skipped
        $country = CountryFactory::make()->getEntity();
        $this->assertNull($country->get('eventApplied'));

        $country = CountryFactory::make()->listeningToModelEvents('Model.beforeMarshal')->getEntity();
        $this->assertNull($country->get('eventApplied'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreBeforeMarshalSetInTable(bool $applyEvent): void
    {
        $name = 'Foo';

        // Event should be skipped
        $country = CountryFactory::make()->getEntity();
        $this->assertNull($country->get('beforeMarshalTriggered'));

        // Event should apply
        $country = $this->Countries->newEntity(compact('name'));
        $this->assertTrue($country->get('beforeMarshalTriggered'));

        $country = CountryFactory::make()->listeningToModelEvents('Model.beforeMarshal')->getEntity();
        $this->assertTrue($country->get('beforeMarshalTriggered'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreEventInBehaviors(bool $applyEvent): void
    {
        $title = 'This Article';
        $slug = 'This-Article';

        $article = ArticleFactory::make(compact('title'))->persist();
        $this->assertEquals(null, $article->get('slug'));

        $article = ArticleFactory::make(compact('title'))->listeningToBehaviors('Sluggable')->persist();
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
        $article = ArticleFactory::make()->listeningToBehaviors($behavior)->getEntity();
        $this->assertInstanceOf(Article::class, $article);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreEventInBehaviorsOnTheFlyWithCountries(bool $applyEvent): void
    {
        $name = 'Some Country';
        $slug = 'Some-Country';

        $country = CountryFactory::make(compact('name'))->persist();
        $this->assertNull($country->get('slug'));

        $country = CountryFactory::make(compact('name'))
            ->listeningToBehaviors('Sluggable')
            ->persist();
        $this->assertEquals($slug, $country->get('slug'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('runSeveralTimesWithOrWithoutEvents')]
    public function testApplyOrIgnoreEventInPluginBehaviorsOnTheFlyWithCountries(bool $applyEvent): void
    {
        $field = SomePluginBehavior::BEFORE_SAVE_FIELD;

        $this->Countries->addBehavior('TestPlugin.SomePlugin');

        // The behavior should not apply
        $country = CountryFactory::make()->persist();
        $this->assertNull($country->get($field));

        // The behavior should apply
        $country = CountryFactory::make()->listeningToBehaviors('SomePlugin')->persist();
        $this->assertTrue($country->get($field));

        // The behavior should not apply
        $country = CountryFactory::make()->persist();
        $this->assertNull($country->get($field));

        // The behavior should apply
        Configure::write('FixtureFactories.testFixtureGlobalBehaviors', ['SomePlugin']);
        $country = CountryFactory::make()->persist();
        $this->assertTrue($country->get($field));
    }

    public function testSkipValidation(): void
    {
        $city = CityFactory::make()->without('Country')->getEntity();
        $this->assertInstanceOf(City::class, $city);
        $this->assertEmpty($city->getErrors());
    }

    public function testSkipValidationInAssociation(): void
    {
        $address = AddressFactory::make()
            ->with('City', CityFactory::make()->without('Country'))
            ->getEntity();
        $this->assertInstanceOf(Address::class, $address);
        $this->assertInstanceOf(City::class, $address->city);
        $this->assertNull($address->city->country);
        $this->assertEmpty($address->getErrors());
    }

    public function testApplyValidationInAssociation(): void
    {
        $address = AddressFactory::make()
            ->with(
                'City',
                CityFactory::make()
                    ->listeningToModelEvents('Model.beforeMarshal')
                    ->without('Country'),
            )
            ->getEntity();
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
        $city = CityFactory::make()->persist();
        $this->assertInstanceOf(City::class, $city);
        $this->assertEmpty($city->getErrors());
    }

    public function testSkipRuleInAssociation(): void
    {
        $address = AddressFactory::make()->getEntity();
        $this->assertInstanceOf(Address::class, $address);
        $this->assertInstanceOf(City::class, $address->city);
        $this->assertEmpty($address->getErrors());
    }

    public function testBeforeMarshalIsTriggeredInAssociationWhenDefinedInDefaultTemplate(): void
    {
        $bill = BillFactory::make()->getEntity();
        $this->assertTrue($bill->get('beforeMarshalTriggeredPerDefault'));

        $bill = CustomerFactory::make()->withBills()->getEntity()->bills[0];
        $this->assertTrue($bill->get('beforeMarshalTriggeredPerDefault'));
    }

    public function testAfterSaveIsTriggeredInAssociationWhenDefinedInDefaultTemplate(): void
    {
        $bill = BillFactory::make()->persist();
        $this->assertTrue($bill->get('afterSaveTriggeredPerDefault'));

        $bill = CustomerFactory::make()->withBills()->persist()->bills[0];
        $this->assertTrue($bill->get('afterSaveTriggeredPerDefault'));
    }

    public function testSetConnection(): void
    {
        $factory = ArticleFactory::make();
        $originalConnectionName = $factory->getTable()->getConnection()->configName();

        // Set a different connection and verify it's applied
        // Note: CakePHP prefixes connection names with 'test_' during testing
        $factory->setConnection('dummy');
        $newConnectionName = $factory->getTable()->getConnection()->configName();

        $this->assertNotSame($originalConnectionName, $newConnectionName);
        $this->assertSame('test_dummy', $newConnectionName);
    }

    public function testSetConnectionChaining(): void
    {
        $article = ArticleFactory::make()
            ->setConnection('dummy')
            ->listeningToBehaviors('Sluggable')
            ->getEntity();

        $this->assertInstanceOf(Article::class, $article);

        // Verify the connection was set correctly
        // Note: CakePHP prefixes connection names with 'test_' during testing
        $factory = ArticleFactory::make()->setConnection('dummy');
        $this->assertSame('test_dummy', $factory->getTable()->getConnection()->configName());
    }
}
