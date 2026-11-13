<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

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
}
