# Fixture Factories

A factory is a class that extends `CakephpFixtureFactories\Factory\BaseFactory`. It should implement the following two methods:
* `getRootTableRegistryName()` which indicates the model, or the table name in PascalCase, where the factory will insert its fixtures;
* `definition()` which returns the default configuration of each entity created by the factory.

The generator is used to randomly populate fields, and is always available via `$this->getGenerator()`.

For example, consider a model `Articles` related to multiple `Authors`.

This could be the `ArticleFactory`. By default the fields `title` and `body` are set with Faker.
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
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'title' => $generator->text(30),
            'body'  => $generator->text(1000),
        ];
    }

    public function hasAuthors(int $n = 1, mixed $parameter = null): static
    {
        return $this->has(AuthorFactory::new($parameter)->count($n));
    }

    /**
     * Set the article's title to a random job title.
     */
    public function setJobTitle(): static
    {
        return $this->setField('title', $this->getGenerator()->jobTitle());
    }
}
```
Add any helper methods that capture your business model — like `setJobTitle()` — to keep factory calls expressive and reusable.

## Named state methods

Prefer small named methods for reusable business states. They read well at the call site and keep one-off inline state arrays from spreading through the test suite.

```php
class ArticleFactory extends BaseFactory
{
    public function published(): static
    {
        return $this->state([
            'is_published' => true,
            'published' => new FrozenTime('-1 day'),
        ]);
    }

    public function featured(): static
    {
        return $this->state([
            'is_featured' => true,
        ]);
    }
}
```

That gives you a compact, intention-revealing API:

```php
$article = ArticleFactory::new()
    ->published()
    ->featured()
    ->save();
```

As a rule of thumb:

- use `definition()` for baseline defaults every entity should get;
- use `state()` / `setField()` for one-off adjustments local to a single test;
- use named methods like `published()`, `archived()`, or `featured()` for reusable business semantics.

Factories are immutable. Every fluent call returns a cloned factory, so reusing a base factory in the same test is safe.

## Required fields

If a field is required in the database, populate it in `definition()`. A fixed value like `1` or `'foo'` is fine.

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

By default, each entity's setters are applied. Deactivate one or more setters by declaring the protected `skippedSetters` property on the factory, or override the set at call time with `skipSetterFor()`.

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
UserFactory::new([
    'username' => 'foo@bar.com',
    'password' => 'secret',
])->skipSetterFor('password')->build();
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
CityFactory::new()->count(5)->with('Countries', 'Foo')->saveMany();
```

creates 5 cities all associated to one country. Run it again and you'll have 10 cities, still tied to that single country.

## Primary keys uniqueness

Primary keys are handled the same way — you don't have to declare them in `$uniqueProperties`. The factory can't read uniqueness constraints from the schema, but it does know which fields are primary keys. So:

```php
CityFactory::new()->count(5)->with('Countries', ['myPrimaryKey' => 1])->saveMany();
```

behaves as if `myPrimaryKey` were marked unique. The factory does the bookkeeping for you.

## Generator-level uniqueness with `->unique()`

`$uniqueProperties` deduplicates **entities** at persist time. If you instead need each *generated value* to be different from the previous ones — typically for fields with a UNIQUE database constraint — use the generator's `->unique()` modifier:

```php
public function definition(GeneratorInterface $generator): array
{
    return [
        'email'    => $generator->unique()->email(),
        'username' => $generator->unique()->userName(),
    ];
}
```

Each subsequent call to `email()` or `userName()` is guaranteed not to repeat a previously returned value. This is useful when:

- the column has a `UNIQUE` constraint (emails, usernames, slugs);
- you're generating many rows in a single test and need them distinguishable.

> [!WARNING]
> `->unique()` retries internally until it finds an unused value, then gives up — Faker throws `OverflowException`, DummyGenerator does the same after 10 000 attempts. Use it only on fields with a large enough value space.
>
> The recommended [`FactoryTransactionStrategy`](setup#factory-transaction-strategy-recommended) resets unique state between tests automatically. If you can't use it, see [Troubleshooting](troubleshooting#overflowexception-from-unique) for the manual reset.

Use `->unique()` on individual fields *inside* the factory; use `$uniqueProperties` to express that an *entire entity* shouldn't be recreated when its identifying field is reused. They solve different problems and compose cleanly.

## Validation / Behaviors

This and the following sub-sections apply to CakePHP applications.

To persist data as straightforwardly as possible, the plugin deactivates all validation and application rules when creating and saving entities. Re-enable or customize them by overriding `$marshallerOptions` and `$saveOptions` on the factory, or by using the immutable setters `setMarshallerOptions()` and `setSaveOptions()` in custom helper methods:

```php
return $this->setMarshallerOptions(['validate' => 'default']);   // merge on top of the defaults
return $this->setSaveOptions(['atomic' => true], merge: false);  // replace the entire option set
```

Both setters merge with existing options by default; pass `merge: false` to replace.

## Keeping built entities dirty (`keepDirty`)

By default, the plugin marks built entities clean before returning them so they don't accidentally appear "modified" to user code. If you intend to hand the entity to `$table->save($entity)` yourself — e.g. to test custom save logic — call `keepDirty()` so required fields stay marked dirty:

```php
$article = ArticleFactory::new()
    ->keepDirty()
    ->build();
$this->Articles->save($article);
```

`keepDirty()` propagates through associations, so any entity in the result tree stays dirty. Pass `keepDirty(false)` to revert.

## Model events and behaviors

By default, *all model events* of a factory's root table and their behaviors are switched off *except for the timestamp behavior*. Factories aim to be fast and transparent — model events would interfere with that.

### Model events

Re-enable a model event with `listeningToModelEvents`. On the fly:

```php
$article = ArticleFactory::new()->listeningToModelEvents('Model.beforeMarshal')->build();
```

Or as a default in `configure()`:
```php
protected function configure(): static
{
    return $this->listeningToModelEvents([
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
$article = ArticleFactory::new()
    ->setEventManager($customEventManager)
    ->listeningToModelEvents('Model.beforeMarshal')
    ->build();
```

The factory's table uses the custom manager instead of the default one. `setEventManager()` follows the same fluent pattern as `setConnection()` and `listeningToBehaviors()`.

### Behavior events

Activate behavior model events the same way, with `listeningToBehaviors`. On the fly:

```php
$article = ArticleFactory::new()->listeningToBehaviors('Sluggable')->build();
```

Or set them as defaults in `configure()`.

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
$article = ArticleFactory::new()
    ->setGenerator('dummy')
    ->build();
```

By default, `setGenerator()` only affects the current factory instance. It returns a clone scoped to the requested generator, leaving other factories on the shared default.

#### Instance-Level Generators

This is controlled by the instance-level generator flag, which defaults to `true`:

```php
Configure::write('FixtureFactories.instanceLevelGenerator', true);
```

With the default configuration:

```php
$factory1 = ArticleFactory::new()->setGenerator('dummy');
$factory2 = ArticleFactory::new();

// $factory1 uses DummyGenerator, $factory2 uses the default (Faker)
```

If you explicitly need the legacy global behavior, disable the flag:

```php
Configure::write('FixtureFactories.instanceLevelGenerator', false);
```

Then `setGenerator()` updates the shared default for subsequent factories.

To set the global default explicitly regardless of this flag, use the static method:

```php
use CakephpFixtureFactories\Factory\BaseFactory;

BaseFactory::setDefaultGenerator('dummy');
```

Keep `instanceLevelGenerator` enabled unless you deliberately want the legacy global `setGenerator()` behavior.

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
