---
title: Usage Examples
description: Common usage patterns for fixture factories
---

# Usage Examples

Here are some examples of how to use the fixture factories.

One article with a random title, as defined in the factory [on the previous page](factories):
```php
$article = ArticleFactory::make()->getEntity();
```
Two articles with different random titles:
```php
$articles = ArticleFactory::make(2)->getEntities();
```
Two articles using the explicit make-many alias:
```php
$articles = ArticleFactory::makeMany(2)->getEntities();
```
One article with title set to 'Foo':
```php
$article = ArticleFactory::make(['title' => 'Foo'])->getEntity();
```
Three articles with the title set to 'Foo':
```php
$articles = ArticleFactory::make(['title' => 'Foo'], 3)->getEntities();
```
or
```php
$articles = ArticleFactory::make(3)->patchData(['title' => 'Foo'])->getEntities();
```
or
```php
$articles = ArticleFactory::make(3)->setField('title', 'Foo')->getEntities();
```
or
```php
$articles = ArticleFactory::make()->setField('title', 'Foo')->setTimes(3)->getEntities();
```
or
```php
$articles = ArticleFactory::make([
 ['title' => 'Foo'],
 ['title' => 'Bar'],
 ['title' => 'Baz'],
])->getEntities();
```

When injecting a single string in the factory, the latter will assign the injected string to the
[display field](https://book.cakephp.org/5/en/orm/retrieving-data-and-resultsets.html#finding-key-value-pairs) of the factory's table:
```php
$articles = ArticleFactory::make('Foo')->getEntity();
$articles = ArticleFactory::make('Foo', 3)->getEntities();
$articles = ArticleFactory::make(['Foo', 'Bar', 'Baz'])->getEntities();
```


To persist the generated data, use `persistOne()` (single entity) or `persistMany()` (multiple entities) instead of `getEntity()` or `getEntities()`:
```php
$article = ArticleFactory::make()->persistOne();
$articles = ArticleFactory::make(3)->persistMany();
```

`persist()` is still available for backwards compatibility but is deprecated; its return type depends on whether the factory was configured for one or many entities, which makes it awkward for static analysis.
You can also build data using an explicit callable:
```php
$article = ArticleFactory::makeWith(function (ArticleFactory $factory, GeneratorInterface $generator) {
    return ['title' => $generator->jobTitle()];
})->getEntity();
```

If you want to manually save an entity using a table instance, keep it dirty so required fields are written:
```php
$article = ArticleFactory::make()
    ->keepDirty()
    ->getEntity();
$this->Articles->save($article);
```

When you add associations, `keepDirty()` also propagates to them:
```php
$article = ArticleFactory::make()
    ->keepDirty()
    ->withAuthors()
    ->getEntity();
$this->Articles->save($article, ['associated' => ['Authors']]);
```

You may want to retrieve your entities as a result set, allowing you to conveniently query the entities created:
```php
$articles = ArticleFactory::make(3)->getResultSet(); // Will not persist in the DB
$articles = ArticleFactory::make(3)->getPersistedResultSet(); // Will persist in the DB
```

A single entity is returned wrapped in a result set as well, so the contract is the same regardless of count:
```php
$article = ArticleFactory::make()->getPersistedResultSet()->first(); // Cake\Datasource\EntityInterface
```

Do not forget to check the [plugin's tests](https://github.com/dereuromark/cakephp-fixture-factories/tree/main/tests) for
more insights!

### Using `FactoryAwareTrait`
All examples above use the static getter to fetch a factory instance. As syntactic sugar, you can use `FactoryAwareTrait::getFactory` instead.

`getFactory` is more tolerant on provided name, as you can use plurals or lowercased names. All arguments passed after factory name will be cast to `BaseFactory::make`.

```php
use App\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Factory\FactoryAwareTrait;

class MyTest extends TestCase
{
    use FactoryAwareTrait;

    public function myTest(): void
    {
        // Static getter style
        $article = ArticleFactory::make()->getEntity();
        $article = ArticleFactory::make(['title' => 'Foo'])->getEntity();
        $articles = ArticleFactory::make(3)->getEntities();
        $articles = ArticleFactory::make(['title' => 'Foo'], 3)->getEntities();

        // Exactly the same in FactoryAwareTrait style
        $article = $this->getFactory('Article')->getEntity();
        $article = $this->getFactory('Article', ['title' => 'Foo'])->getEntity();
        $articles = $this->getFactory('Article', 3)->getEntities();
        $articles = $this->getFactory('Article', ['title' => 'Foo'], 3)->getEntities();
    }
}
```

### Chaining methods

Factories let you express business semantics by chaining methods. Any method that returns `$this` can be chained, and you can chain as many as you want.

The example below uses a custom method on `ArticleFactory` to set a job-title body. It's deliberately simple — your real chains will encode whatever business patterns you have.
```php
$articleFactory = ArticleFactory::make(['title' => 'Foo']);
$articleFoo1 = $articleFactory->persistOne();
$articleFoo2 = $articleFactory->persistOne();
$articleJobOffer = $articleFactory->setJobTitle()->persistOne();
```

The first two articles have a title set to 'Foo'. The third has a job title, randomly generated by the configured generator as defined in the `ArticleFactory`.

### With a callable

If a field is not specified via the generator inside `setDefaultTemplate`, all the generated rows for that factory will share the same value. The example below generates three articles with three different random titles:
```php
use App\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;
...
$articles = ArticleFactory::make(function(ArticleFactory $factory, GeneratorInterface $generator) {
   return [
       'title' => $generator->text(),
   ];
}, 3)->persistMany();
```

### Dot notation for array fields

You might come across fields storing data in array format, with a given default value set in your factories.
It is possible to overwrite only a part of the array using the dot notation.

Considering for example that the field `array_field` stores an array with keys `key1` and `key2`, you can
overwrite the value of `key2` only and keep the default value of `key1` as follows:

```php
use App\Test\Factory\ArticleFactory;
...
$article = ArticleFactory::make(['array_field.key2' => 'newValue'])->getEntity();
// or
$article = ArticleFactory::make([
   'array_field.key1' => 'foo',
   'array_field.key2' => 'bar',
])->getEntity();
// or
$article = ArticleFactory::make()->setField('array_field.key2', 'newValue')->getEntity();
```

### Mocking select queries

You might come across tests where you want to avoid the communication
with the database, and yet you would need to simulate the output of a select query.

For example in a `ArticlesIndexController` you want to emulate a query returning
10 articles and want to test that the rendering is made properly.

In your test, where `$this` is the TestCase extending [CakePHP's TestCase](https://book.cakephp.org/5/en/development/testing.html#mocking-model-methods):
```php
$articleFactory = ArticleFactory::make(10)->withAuthors();
\CakephpFixtureFactories\ORM\SelectQueryMocker::mock($this, $articleFactory);
```

Any select queries on the `ArticlesTable` will now return these 10 articles with their associations.
The queries themselves, involving the interaction with the DB, should be tested elsewhere.
