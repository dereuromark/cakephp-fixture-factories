<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\PluginCountryFactory;
use CakephpFixtureFactories\TestSuite\TruncateDirtyTables;
use TestApp\Model\Table\CitiesTable;
use TestApp\Model\Table\CountriesTable;

/**
 * An association may name its target by class. Its registry alias is then
 * the association name, so the related factory has to be matched by class.
 */
class ClassNameAssociationResolutionTest extends TestCase
{
    use TruncateDirtyTables;

    protected function tearDown(): void
    {
        // The tests add associations to shared factory tables.
        FactoryTableRegistry::getTableLocator()->clear();

        parent::tearDown();
    }

    public function testForWithAliasAcceptsClassNameAssociation(): void
    {
        $factory = CityFactory::new();
        $factory->getTable()->belongsTo('Homeland', [
            'className' => CountriesTable::class,
            'foreignKey' => 'country_id',
        ]);

        $city = $factory->for(CountryFactory::new(['name' => 'Foo']), 'Homeland')->save();

        $this->assertSame('Foo', $city->get('homeland')->get('name'));
    }

    public function testHasWithAliasAcceptsClassNameAssociation(): void
    {
        $factory = PluginCountryFactory::new();
        $factory->getTable()->hasMany('Towns', [
            'className' => CitiesTable::class,
            'foreignKey' => 'country_id',
        ]);

        $country = $factory->has(CityFactory::new(['name' => 'Foo']), 'Towns')->save();

        $this->assertSame('Foo', $country->get('towns')[0]->get('name'));
    }

    public function testHasWithoutAliasResolvesClassNameAssociation(): void
    {
        $factory = PluginCountryFactory::new();
        $factory->getTable()->hasMany('Towns', [
            'className' => CitiesTable::class,
            'foreignKey' => 'country_id',
        ]);

        $country = $factory->has(CityFactory::new(['name' => 'Foo']))->save();

        $this->assertSame('Foo', $country->get('towns')[0]->get('name'));
    }
}
