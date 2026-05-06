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
$articles = ArticleFactory::new()->count(3)->state(['title' => 'Foo'])->buildMany();
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

To vary attributes across `count()` calls, use `sequence()`:
```php
$articles = ArticleFactory::new()
    ->count(4)
    ->sequence(
        ['title' => 'Draft'],
        ['title' => 'Published'],
    )
    ->buildMany();
```

The state at index `$i % count($states)` is applied to the i-th entity, so the example above produces `Draft, Published, Draft, Published`. If `count()` is smaller than the number of states the trailing states are simply unused; if `count()` is `1` only the first state ever applies. Calling `sequence()` again replaces the previously stored states — it is not additive.

A `sequence` state can also be a callable receiving `($factory, $generator, $index)` for index-aware values:

```php
$articles = ArticleFactory::new()
    ->count(3)
    ->sequence(fn ($factory, $generator, int $i) => ['slug' => "article-{$i}"])
    ->buildMany();
```

For reusable business states, prefer named methods on the factory:
```php
$article = ArticleFactory::new()
    ->published()
    ->featured()
    ->save();
```

Use inline `state()` or `setField()` when the adjustment is specific to this test only:
```php
$article = ArticleFactory::new()
    ->published()
    ->state(['reviewed_by' => 'qa-user'])
    ->save();
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

`save()` and `build()` throw a `RuntimeException` when the factory is configured to produce more than one entity (`count(>1)` or a multi-row instantiation array). Use `saveMany()` / `buildMany()` whenever the count is anything other than exactly one.

You can also build data using an explicit callable:
```php
$article = ArticleFactory::new(function (ArticleFactory $factory, GeneratorInterface $generator) {
    return ['title' => $generator->jobTitle()];
})->build();
```

You can also hook into the lifecycle of generated entities:
```php
$article = ArticleFactory::new()
    ->afterBuild(function (Article $article): void {
        $article->title = 'Built title';
    })
    ->afterSave(function (Article $article): void {
        $article->title = 'Saved title';
    })
    ->save();
```

`afterBuild()` runs before `build*()` returns and before `save*()` persists.
`afterSave()` runs after the entity has been saved, so it can adjust the in-memory entity without rewriting the database row.

Both hooks receive `($entity, int $index, BaseFactory $factory)`, so you can vary behavior per row:

```php
$articles = ArticleFactory::new()
    ->count(3)
    ->afterBuild(function (Article $article, int $index): void {
        $article->title = sprintf('Article #%d', $index + 1);
    })
    ->buildMany();
```

> **Caveat — `Model.afterSaveCommit`.** Cake fires `afterSaveCommit` only after the outer transaction commits. Under `FactoryTransactionStrategy` (the recommended setup) the outer transaction always rolls back at teardown, so `afterSaveCommit` listeners do not fire for factory-created entities. If your behavior depends on `afterSaveCommit`, exercise it through application code instead, or run the relevant test under a non-transactional strategy.

Both hooks fire for nested factories too — when a child factory is persisted as part of its parent's cascading save, its own `afterSave()` callbacks run on the saved child entities:

```php
ArticleFactory::new()
    ->with('Authors', AuthorFactory::new()->afterSave(function (Author $author) {
        // runs once per saved author, even though save() was called on the article factory
    }))
    ->save();
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

If a test wants CakePHP `ResultSet` semantics, wrap the array returned by
`buildMany()` or `saveMany()` explicitly. There are no dedicated factory
`ResultSet` helpers in v2:
```php
$articles = new \Cake\ORM\ResultSet(
    ArticleFactory::new()->count(3)->buildMany()
); // In-memory only

$articles = new \Cake\ORM\ResultSet(
    ArticleFactory::new()->count(3)->saveMany()
); // Persisted rows
```

A single entity can be normalized the same way when a caller expects a
`ResultSet` contract:
```php
$article = (new \Cake\ORM\ResultSet(ArticleFactory::new()->saveMany()))->first(); // Cake\Datasource\EntityInterface
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

The same chaining style works especially well for named state methods:
```php
$article = ArticleFactory::new()
    ->published()
    ->featured()
    ->hasAuthors(2)
    ->save();
```

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
