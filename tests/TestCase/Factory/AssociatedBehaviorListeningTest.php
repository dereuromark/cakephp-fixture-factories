<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\BillFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\CustomerFactory;
use CakephpFixtureFactories\Test\Factory\SluggableCountryFactory;
use CakephpFixtureFactories\TestSuite\TruncateDirtyTables;

class AssociatedBehaviorListeningTest extends TestCase
{
    use TruncateDirtyTables;

    protected function tearDown(): void
    {
        // A test attaches a listener to a shared factory table.
        FactoryTableRegistry::getTableLocator()->clear();

        parent::tearDown();
    }

    public function testBelongsToFactoryListensToItsBehaviors(): void
    {
        $city = CityFactory::new()
            ->with('Countries', CountryFactory::new(['name' => 'Some Country'])->listeningToBehaviors('Sluggable'))
            ->save();

        $this->assertSame('Some-Country', $city->get('country')->get('slug'));
    }

    public function testBelongsToFactoryIgnoresBehaviorsByDefault(): void
    {
        $city = CityFactory::new()
            ->with('Countries', CountryFactory::new(['name' => 'Some Country']))
            ->save();

        $this->assertNull($city->get('country')->get('slug'));
    }

    public function testBelongsToFactoryListensToItsConfiguredBehaviors(): void
    {
        $city = CityFactory::new()
            ->with('Countries', SluggableCountryFactory::new(['name' => 'Some Country']))
            ->save();

        $this->assertSame('Some-Country', $city->get('country')->get('slug'));
    }

    public function testNestedFactoryListensToItsBehaviors(): void
    {
        $address = AddressFactory::new()
            ->with('City', CityFactory::new()->with(
                'Countries',
                CountryFactory::new(['name' => 'Some Country'])->listeningToBehaviors('Sluggable'),
            ))
            ->save();

        $this->assertSame('Some-Country', $address->get('city')->get('country')->get('slug'));
    }

    public function testAssociationTargetIsRestoredAfterSave(): void
    {
        $association = CityFactory::new()->getTable()->getAssociation('Countries');
        $target = $association->getTarget();

        CityFactory::new()
            ->with('Countries', CountryFactory::new()->listeningToBehaviors('Sluggable'))
            ->save();

        $this->assertSame($target, $association->getTarget());
    }

    public function testSiblingBranchesWithDifferentListeningKeepOriginalTarget(): void
    {
        $author = AuthorFactory::new()
            ->with('Address', AddressFactory::new()->with(
                'City',
                CityFactory::new()->with(
                    'Countries',
                    CountryFactory::new(['name' => 'Some Country'])->listeningToBehaviors('Sluggable'),
                ),
            ))
            ->with('BusinessAddress', AddressFactory::new()->with(
                'City',
                CityFactory::new()->with('Countries', CountryFactory::new(['name' => 'Other Country'])),
            ))
            ->save();

        $this->assertNull($author->get('business_address')->get('city')->get('country')->get('slug'));
    }

    public function testAssociatedAfterSaveFiresOnce(): void
    {
        $billFactory = BillFactory::new()->listeningToModelEvents('Model.afterSave');
        $count = 0;
        $billFactory->getTable()->getEventManager()->on('Model.afterSave', function () use (&$count): void {
            $count++;
        });

        $customer = CustomerFactory::new()->with('Bills', $billFactory)->save();

        $this->assertTrue($customer->get('bills')[0]->get('afterSaveTriggeredPerDefault'));
        $this->assertSame(1, $count);
    }

    public function testForeignKeyFalseBelongsToFactoryListensToItsBehaviors(): void
    {
        $countryFactory = CountryFactory::new(['name' => 'Ghost Country'])->listeningToBehaviors('Sluggable');
        // The country is not attached to the author, so read the slug off the saved entity.
        $slug = null;
        $countryFactory->getTable()->getEventManager()->on(
            'Model.afterSave',
            function (EventInterface $event, EntityInterface $entity) use (&$slug): void {
                $slug = $entity->get('slug');
            },
        );

        $factory = AuthorFactory::new();
        $factory->getTable()->belongsTo('GhostCountry', [
            'className' => 'Countries',
            'foreignKey' => false,
            'conditions' => ['Authors.name = GhostCountry.name'],
        ]);

        // The join column doubles as a definition() field, which is not what this test is about.
        $strict = Configure::read('FixtureFactories.strictDefinition');
        Configure::write('FixtureFactories.strictDefinition', false);
        try {
            $factory->with('GhostCountry', $countryFactory)->save();
        } finally {
            Configure::write('FixtureFactories.strictDefinition', $strict);
        }

        $this->assertSame('Ghost-Country', $slug);
    }

    public function testAppendedToManyFactoriesWithDifferentListeningKeepOriginalTarget(): void
    {
        $listening = BillFactory::new()->listeningToModelEvents('Model.beforeSave');
        $count = 0;
        $listening->getTable()->getEventManager()->on('Model.beforeSave', function () use (&$count): void {
            $count++;
        });

        $customer = CustomerFactory::new()
            ->with('Bills', BillFactory::new())
            ->with('Bills', $listening)
            ->save();

        $this->assertCount(2, $customer->get('bills'));
        $this->assertSame(0, $count);
    }

    public function testAssociatedAfterSaveFiresOnceWhenAListenerStopsTheEvent(): void
    {
        $billFactory = BillFactory::new()->listeningToModelEvents('Model.afterSave');
        $count = 0;
        $billFactory->getTable()->getEventManager()->on('Model.afterSave', function (EventInterface $event) use (&$count): void {
            $count++;
            $event->stopPropagation();
        });

        CustomerFactory::new()->with('Bills', $billFactory)->save();

        $this->assertSame(1, $count);
    }

    public function testContestedBranchAfterSaveFiresOncePerFactoryTable(): void
    {
        $listening = CountryFactory::new(['name' => 'Other Country'])->listeningToModelEvents('Model.afterSave');
        $count = 0;
        // The shared target may be this very table, so only its own country counts.
        $listening->getTable()->getEventManager()->on(
            'Model.afterSave',
            function (EventInterface $event, EntityInterface $entity) use (&$count): void {
                if ($entity->get('name') === 'Other Country') {
                    $count++;
                }
            },
        );

        AuthorFactory::new()
            ->with('Address', AddressFactory::new()->with(
                'City',
                CityFactory::new()->with('Countries', CountryFactory::new(['name' => 'Some Country'])),
            ))
            ->with('BusinessAddress', AddressFactory::new()->with('City', CityFactory::new()->with('Countries', $listening)))
            ->save();

        $this->assertSame(1, $count);
    }
}
