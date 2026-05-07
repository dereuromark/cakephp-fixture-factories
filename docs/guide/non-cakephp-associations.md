# Associations for non-CakePHP apps

Associations can be defined within the factories in the `initialize()` method.
The `getTable()` method provides public access to the model class used by the factories. If not defined in your application
(which is probably the case if not built with CakePHP), the model class is generated automatically,
based on the table name returned by the `getRootTableRegistryName` method.

For example, considering the following schema:

| addresses | cities     | countries |
|-----------|------------|-----------|
| id        | id         | id        |
| street    | name       | name      |
| city_id   | country_id | created   |
| created   | created    | modified  |
| modified  | modified   |           |

First create the `CityFactory`, `AddressFactory` and `CountryFactory` classes as described in [Fixture Factories](factories).

In the `CityFactory`, you may then associate the `cities`
belonging to a `country` and having many `addresses` in the `initialize` method:

```php
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class CityFactory extends BaseFactory
{
    protected function initialize(): void
    {
        $this->getTable()
            ->belongsTo('Country')
            ->hasMany('Addresses');
    }

    protected function getRootTableRegistryName(): string
    {
        return 'Cities';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->city(),
        ];
    }
}
```

Once this is defined, you may then call:
```php
$city = CityFactory::new()
    ->with('Addresses', 4)
    ->for(CountryFactory::new(['name' => 'India']))
    ->build();
```
which will set the city's country, and provide 4 random addresses.

The [CakePHP cookbook chapter on associations](https://book.cakephp.org/5/en/orm/associations.html) describes how to define your associations.
Non-CakePHP applications don't need to create any table objects — use the `getTable()` public method instead.
