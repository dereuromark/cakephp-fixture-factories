---
title: Usage Examples
description: Common usage patterns for fixture factories
---

# Usage Examples

Here are some examples of how to use the fixture factories.

One article with a random title, as defined in the factory [on the previous page](factories):
```php
$article = ArticleFactory::new()->build();
```
Two articles with different random titles:
```php
$articles = ArticleFactory::new()->count(2)->buildMany();
```
One article with title set to 'Foo':
```php
$article = ArticleFactory::new(['title' => 'Foo'])->build();
```
Three articles with the title set to 'Foo':
```php
$articles = ArticleFactory::new(['title' => 'Foo'])->count(3)->buildMany();
```
or
```php
$articles = ArticleFactory::new()->count(3)->patchData(['title' => 'Foo'])->buildMany();
```
or
```php
$articles = ArticleFactory::new()->count(3)->setField('title', 'Foo')->buildMany();
```
or
```php
$articles = ArticleFactory::new()->setField('title', 'Foo')->count(3)->buildMany();
```
or
```php
$articles = ArticleFactory::new([
 ['title' => 'Foo'],
 ['title' => 'Bar'],
 ['title' => 'Baz'],
])->buildMany();
```

When injecting a single string in the factory, the latter will assign the injected string to the
[display field](https://book.cakephp.org/5/en/orm/retrieving-data-and-resultsets.html#finding-key-value-pairs) of the factory's table:
```php
$article = ArticleFactory::new('Foo')->build();
$articles = ArticleFactory::new('Foo')->count(3)->buildMany();
$articles = ArticleFactory::new(['Foo', 'Bar', 'Baz'])->buildMany();
```


To persist the generated data, use `save()` (single entity) or `saveMany()` (multiple entities) instead of `build()` or `buildMany()`:
```php
$article = ArticleFactory::new()->save();
$articles = ArticleFactory::new()->count(3)->saveMany();
```

You can also build data using an explicit callable:
```php
$article = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
    return ['title' => $generator->jobTitle()];
})->build();
```

If you want to manually save an entity using a table instance, keep it dirty so required fields are written:
```php
$article = ArticleFactory::new()
    ->keepDirty()
    ->build();
$this->Articles->save($article);
```

When you add associations, `keepDirty()` also propagates to them:
```php
$article = ArticleFactory::new()
    ->keepDirty()
    ->hasAuthors()
    ->build();
$this->Articles->save($article, ['associated' => ['Authors']]);
```

You may want to retrieve your entities as a result set, allowing you to conveniently query the entities created:
```php
$articles = ArticleFactory::new()->count(3)->getResultSet(); // Will not persist in the DB
$articles = ArticleFactory::new()->count(3)->getPersistedResultSet(); // Will persist in the DB
```

A single entity is returned wrapped in a result set as well, so the contract is the same regardless of count:
```php
$article = ArticleFactory::new()->getPersistedResultSet()->first(); // Cake\Datasource\EntityInterface
```

Do not forget to check the [plugin's tests](https://github.com/dereuromark/cakephp-fixture-factories/tree/main/tests) for
more insights!

### Using `FactoryAwareTrait`
All examples above use the static getter to fetch a factory instance. As syntactic sugar, you can use `FactoryAwareTrait::getFactory` instead.

`getFactory` is more tolerant on provided name, as you can use plurals or lowercased names. All arguments passed after factory name will be cast to `BaseFactory::new`.

```php
use App\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Factory\FactoryAwareTrait;

class MyTest extends TestCase
{
    use FactoryAwareTrait;

    public function myTest(): void
    {
        // Static getter style
        $article = ArticleFactory::new()->build();
        $article = ArticleFactory::new(['title' => 'Foo'])->build();
        $articles = ArticleFactory::new()->count(3)->buildMany();
        $articles = ArticleFactory::new(['title' => 'Foo'])->count(3)->buildMany();

        // Exactly the same in FactoryAwareTrait style
        $article = $this->getFactory('Article')->build();
        $article = $this->getFactory('Article', ['title' => 'Foo'])->build();
        $articles = $this->getFactory('Article', 3)->buildMany();
        $articles = $this->getFactory('Article', ['title' => 'Foo'])->count(3)->buildMany();
    }
}
```

### Chaining methods

Factories let you express business semantics by chaining methods. Any method that returns `$this` can be chained, and you can chain as many as you want.

The example below uses a custom method on `ArticleFactory` to set a job-title body. It's deliberately simple — your real chains will encode whatever business patterns you have.
```php
$articleFactory = ArticleFactory::new(['title' => 'Foo']);
$articleFoo1 = $articleFactory->save();
$articleFoo2 = $articleFactory->save();
$articleJobOffer = $articleFactory->setJobTitle()->save();
```

The first two articles have a title set to 'Foo'. The third has a job title, randomly generated by the configured generator as defined in the `ArticleFactory`.

### With a callable

If a field is not specified via the generator inside `definition()`, all the generated rows for that factory will share the same value. The example below generates three articles with three different random titles:
```php
use App\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
...
$articles = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
   return [
       'title' => $generator->text(),
   ];
})->count(3)->saveMany();
```

### Dot notation for array fields

You might come across fields storing data in array format, with a given default value set in your factories.
It is possible to overwrite only a part of the array using the dot notation.

Considering for example that the field `array_field` stores an array with keys `key1` and `key2`, you can
overwrite the value of `key2` only and keep the default value of `key1` as follows:

```php
use App\Test\Factory\ArticleFactory;
...
$article = ArticleFactory::new(['array_field.key2' => 'newValue'])->build();
// or
$article = ArticleFactory::new([
   'array_field.key1' => 'foo',
   'array_field.key2' => 'bar',
])->build();
// or
$article = ArticleFactory::new()->setField('array_field.key2', 'newValue')->build();
```

### Mocking select queries

You might come across tests where you want to avoid the communication
with the database, and yet you would need to simulate the output of a select query.

For example in a `ArticlesIndexController` you want to emulate a query returning
10 articles and want to test that the rendering is made properly.

In your test, where `$this` is the TestCase extending [CakePHP's TestCase](https://book.cakephp.org/5/en/development/testing.html#mocking-model-methods):
```php
$articleFactory = ArticleFactory::new()->count(10)->hasAuthors();
\CakephpFixtureFactories\ORM\SelectQueryMocker::mock($this, $articleFactory);
```

Any select queries on the `ArticlesTable` will now return these 10 articles with their associations.
The queries themselves, involving the interaction with the DB, should be tested elsewhere.
