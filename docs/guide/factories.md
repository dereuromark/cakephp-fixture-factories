# Fixture Factories

A factory is a class that extends the `CakephpFixtureFactories\Factory\BaseFactory`. It should implement the following two methods:
* `getRootTableRegistryName()`  which indicates the model, or the table name in PascalCase, where the factory will insert its fixtures;
* `setDefaultTemplate()`  which sets the default configuration of each entity created by the factory.

The generator is used to randomly populate fields, and is always available via `$this->getGenerator()`.

For example, consider a model `Articles` related to multiple `Authors`.

This could be the `ArticleFactory`. By default the fields `title` and `body` are set with Faker and two associated `Authors` are created.
```php
namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class ArticleFactory extends BaseFactory
{
    /**
     * Defines the Table Registry used to generate entities with.
     */
    protected function getRootTableRegistryName(): string
    {
        return 'Articles'; // PascalCase of the factory's table.
    }

    /**
     * Defines the default values of your factory.
     * Useful for not-nullable fields, and a place to set up default associations.
     */
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(function (GeneratorInterface $generator) {
            return [
                'title' => $generator->text(30),
                'body'  => $generator->text(1000),
            ];
        })
        ->withAuthors(2);
    }

    public function withAuthors(mixed $parameter = null, int $n = 1): self
    {
        return $this->with('Authors', AuthorFactory::make($parameter, $n));
    }

    /**
     * Set the article's title to a random job title.
     */
    public function setJobTitle(): self
    {
        return $this->setField('title', $this->getGenerator()->jobTitle());
    }
}
```
Add any helper methods that capture your business model — like `setJobTitle()` — to keep factory calls expressive and reusable.

## Required fields

If a field is required in the database, populate it in `setDefaultTemplate`. A fixed value like `1` or `'foo'` is fine.

## Locale

Factories generate data in your application's locale, when supported by Faker.

## Namespace

Assuming your application namespace is `App`, factories belong in `App\Test\Factory`. For a `Foo` plugin, use `Foo\Test\Factory`.

Change the namespace by setting `FixtureFactories.testFixtureNamespace` — typically in `tests/bootstrap.php` or a test's `setUp()`:
```php
use Cake\Core\Configure;

Configure::write('FixtureFactories.testFixtureNamespace', 'MyApp\Test\Factory');
```

## Setters

By default, each entity's setters are applied. Deactivate one or more setters by declaring the protected `skippedSetters` property on the factory, or override the set at call time with `skipSettersFor()`.

```php
namespace App\Test\Factory;
...
class UserFactory extends BaseFactory
{
    protected array $skippedSetters = [
        'password',
    ];
...
}
```

or

```php
UserFactory::make([
    'username' => 'foo@bar.com',
    'password' => 'secret',
])->skipSetterFor('password')->getEntity();
```

This can be useful for setters with heavy computation costs, such as hashing.

## Property uniqueness

It's common to need entities associated with another entity that should remain constant — not
recreated once it has already been persisted. For example, if you create 5 cities within a country,
you don't want 5 countries to be created. That would also likely collide with your schema's
constraints. The same applies to primary keys.

You can declare unique properties via the protected `$uniqueProperties` array. For example, given a
country factory:

```php
namespace App\Test\Factory;
...
class CountryFactory extends BaseFactory
{
    protected array $uniqueProperties = [
        'name',
    ];
...
}
```

Because `name` is marked unique, the country factory de-duplicates whenever a developer sets `name`.

```php
CityFactory::make(5)->with('Countries', 'Foo')->persist();
```

creates 5 cities all associated to one country. Run it again and you'll have 10 cities, still tied to that single country.

## Primary keys uniqueness

Primary keys are handled the same way — you don't have to declare them in `$uniqueProperties`. The factory can't read uniqueness constraints from the schema, but it does know which fields are primary keys. So:

```php
CityFactory::make(5)->with('Countries', ['myPrimaryKey' => 1])->persist();
```

behaves as if `myPrimaryKey` were marked unique. The factory does the bookkeeping for you.

## Validation / Behaviors

This and the following sub-sections apply to CakePHP applications.

To persist data as straightforwardly as possible, the plugin deactivates all validation and application rules when creating and saving entities. Re-enable or customize them by overriding `$marshallerOptions` and `$saveOptions` on the factory.

## Model events and behaviors

By default, *all model events* of a factory's root table and their behaviors are switched off *except for the timestamp behavior*. Factories aim to be fast and transparent — model events would interfere with that.

### Model events

Re-enable a model event with `listeningToModelEvents`. On the fly:

```php
$article = ArticleFactory::make()->listeningToModelEvents('Model.beforeMarshal')->getEntity();
```

Or as a default in `initialize()`:
```php
protected function initialize(): void
{
      $this->listeningToModelEvents([
        'Model.beforeMarshal',
        'Model.beforeSave',
      ]);
}
```

Pass either a single event or an array of events. The full list lives in the [CakePHP cookbook](https://book.cakephp.org/5/en/orm/table-objects.html#event-list).

### Custom event managers

You may want a custom event manager instance for your factories — for example to:

- test event listeners in isolation,
- use a pre-configured manager with specific listeners attached,
- control event propagation.

Set a custom manager via `setEventManager()`:

```php
$article = ArticleFactory::make()
    ->setEventManager($customEventManager)
    ->listeningToModelEvents('Model.beforeMarshal')
    ->getEntity();
```

The factory's table uses the custom manager instead of the default one. `setEventManager()` follows the same fluent pattern as `setConnection()` and `listeningToBehaviors()`.

### Behavior events

Activate behavior model events the same way, with `listeningToBehaviors`. On the fly:

```php
$article = ArticleFactory::make()->listeningToBehaviors('Sluggable')->getEntity();
```

Or set them as defaults in `setDefaultTemplate`.

You can also declare a behavior globally — useful for behaviors that touch many tables and need to populate not-nullable fields. Configure global behaviors via `FixtureFactories.testFixtureGlobalBehaviors`. The root table must already listen for the behavior:

```php
use Cake\Core\Configure;

Configure::write('FixtureFactories.testFixtureGlobalBehaviors', [
    'SomeBehaviorUsedInMultipleTables',
]);
```

Even if the behavior lives in a plugin, provide the bare name (`BehaviorName`) — not the plugin-prefixed form (`SomeVendor/WithPluginName.BehaviorName`) — per CakePHP convention.

## Generator Configuration

### Switching Data Generators

By default, the factories use the [Faker](https://github.com/fakerphp/faker) library for generating random data.
This provides full backward compatibility with the original plugin.
Make sure to include the dependency as a "require-dev" in your `composer.json`.

Alternatively, you can switch to [DummyGenerator](https://github.com/johnykvsky/dummygenerator) which is a leaner API with active support.

You can also use any custom generator, see below.

#### Global Configuration

To change the generator globally, set the configuration key in your test bootstrap or `config/app.php`:

```php
use Cake\Core\Configure;

// Use Faker (default)
Configure::write('FixtureFactories.generatorType', 'faker');

// Switch to DummyGenerator
Configure::write('FixtureFactories.generatorType', 'dummy');
```

> **Tip**: See `config/app.example.php` in this plugin for a full list of available configuration options.

#### Per-Factory Configuration

You can switch generators on a per-factory basis:

```php
$article = ArticleFactory::make()
    ->setGenerator('dummy')
    ->getEntity();
```

By default, `setGenerator()` changes the generator globally for all factory instances. This preserves backward compatibility.

##### Instance-Level Generators

If you want `setGenerator()` to only affect the current factory instance, enable the instance-level generator flag:

```php
Configure::write('FixtureFactories.instanceLevelGenerator', true);
```

With this enabled:

```php
$factory1 = ArticleFactory::make()->setGenerator('dummy');
$factory2 = ArticleFactory::make();

// $factory1 uses DummyGenerator, $factory2 uses the default (Faker)
```

To explicitly set the global default regardless of this flag, use the static method:

```php
use CakephpFixtureFactories\Factory\BaseFactory;

BaseFactory::setDefaultGenerator('dummy');
```

We recommend enabling `instanceLevelGenerator` in new projects to avoid surprising side effects when switching generators in individual factories.

#### Seeding

By default, the generator is seeded with `1234` for reproducible test data. You can configure a different seed:

```php
Configure::write('FixtureFactories.seed', 9999);
```

#### Resetting Generator State

When using the recommended `FactoryTransactionStrategy` (see [Setup](setup)), generator unique state is automatically reset between tests.

If you need to manually reset the generator state (e.g. in a custom test setup), use:

```php
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;

// Clear cached generator instances
CakeGeneratorFactory::clearInstances();

// Clear the default generator (will be re-created on next access)
BaseFactory::resetDefaultGenerator();
```

#### Available Generators

The following generators are available out of the box:
- **faker** (default): [FakerPHP/Faker](https://github.com/FakerPHP/Faker) - Full-featured data generation
- **dummy**: [johnykvsky/dummygenerator](https://github.com/johnykvsky/dummygenerator) - Modern and lean PHP 8.3+ generator, supports enums natively.

Both are included as "require-dev" dependencies in this plugin.
Choose the one you want to use and "require" it.

> **Note**: For a detailed comparison of available methods and migration guide, see [Generators](generators).

Both should offer the same generator methods as we shim the extra ones respectively.

#### Custom Generators

You can create custom generators by:

1. Implementing the `CakephpFixtureFactories\Generator\GeneratorInterface`
2. Registering your adapter:

```php
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;

CakeGeneratorFactory::registerAdapter('custom', MyCustomAdapter::class);
```

3. Using it in your factories:

```php
Configure::write('FixtureFactories.generatorType', 'custom');
```
