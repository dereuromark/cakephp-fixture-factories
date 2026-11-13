<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\AddressFactory;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Factory\PluginCountryFactory;
use CakephpFixtureFactories\TestSuite\TruncateDirtyTables;
use TestApp\Model\Table\CountriesTable;
use TestPlugin\Model\Table\CountriesTable as PluginCountriesTable;

/**
 * An app table and a plugin table may share a bare alias (`Countries` and
 * `TestPlugin.Countries`). Their factories must not fight over it.
 */
class BareAliasCollisionTest extends TestCase
{
    use TruncateDirtyTables;

    protected function tearDown(): void
    {
        // The tests swap an association on a shared factory table.
        FactoryTableRegistry::getTableLocator()->clear();

        parent::tearDown();
    }

    public function testForResolvesAppTableAfterPluginFactoryRan(): void
    {
        PluginCountryFactory::new()->save();

        $city = $this->cityFactoryWithClassNameAssociation()
            ->for(CountryFactory::new(['name' => 'Foo']))
            ->save();

        $this->assertSame('Foo', $city->get('country')->get('name'));
    }

    public function testUnrelatedAssociationDoesNotCollide(): void
    {
        PluginCountryFactory::new()->save();

        $city = $this->cityFactoryWithClassNameAssociation()
            ->has(AddressFactory::new(['street' => 'Foo']))
            ->save();

        $this->assertSame('Foo', $city->get('addresses')[0]->get('street'));
    }

    public function testBareAliasStaysWithAppTable(): void
    {
        PluginCountryFactory::new()->save();

        $this->assertInstanceOf(CountriesTable::class, FactoryTableRegistry::getTableLocator()->get('Countries'));
        $this->assertInstanceOf(
            PluginCountriesTable::class,
            FactoryTableRegistry::getTableLocator()->get('TestPlugin.Countries'),
        );
    }

    public function testPluginFactoryStillUsesPluginTableAfterAppFactoryRan(): void
    {
        CountryFactory::new()->save();

        $this->assertInstanceOf(PluginCountriesTable::class, PluginCountryFactory::new()->getTable());
        $this->assertInstanceOf(CountriesTable::class, CountryFactory::new()->getTable());
    }

    /**
     * The collision needs an association whose `className` is a class name:
     * Cake then resolves the target by the bare association name.
     *
     * @return \CakephpFixtureFactories\Test\Factory\CityFactory
     */
    private function cityFactoryWithClassNameAssociation(): CityFactory
    {
        $factory = CityFactory::new();
        $table = $factory->getTable();
        $table->associations()->remove('Countries');
        $table->belongsTo('Countries', ['className' => CountriesTable::class]);

        return $factory;
    }
}
