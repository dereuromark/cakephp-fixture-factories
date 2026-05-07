# Associations

If your application is not using CakePHP, or if you want to add
associations within your factories, please take a look at the [Associations for non-CakePHP apps](non-cakephp-associations) section
in order to define the associations of your tables. After defining your associations, you may
continue with the documentation below.

## The association API
If you have baked your factories with the option `-m` or `--methods`, you will have noticed that a method for each association
has been inserted in the factories. These directional helpers make the cardinality explicit at the call site. For example, we can
create an article with 10 authors as follows:
```php
$article = ArticleFactory::new()->with('Authors', AuthorFactory::new()->count(10))->save();
```
or using the method defined in our `ArticleFactory`:
```php
$article = ArticleFactory::new()->hasAuthors(10)->save();
```

If we wish to randomly populate the field `biography` of the 10 authors of our article, with 10 different biographies:
```php
$article = ArticleFactory::new()->hasAuthors(10, function (AuthorFactory $factory, GeneratorInterface $generator) {
    return [
        'biography' => $generator->realText(),
    ];
})->save();
```
It is also possible to use the _dot_ notation to create associated fixtures:
```php
$article = ArticleFactory::new()->with('Authors.Address.City.Country', ['name' => 'Kenya'])->save();
```
will create an article, with an author having itself an address in Kenya.

The second parameter of `with()` can be:
* an array of fields and their values
* a string (or an array of strings), which will be assigned to the table's display field
* an integer: the number of associated entities created
* a factory

Ultimately, the square bracket notation provides a mean to specify the number of associated
data created:
```php
$article = ArticleFactory::new()->count(5)->with('Authors[3].Address.City.Country', ['name' => 'Kenya'])->saveMany();
```
will create 5 articles, having themselves each 3 different associated authors, all located in Kenya.

It is also possible to specify the fields of a toMany associated model.
For example, if we wish to create a random country with two cities having known names:

```php
$country = CountryFactory::new()->with('Cities', [
    ['name' => 'Nairobi'],
    ['name' => 'Mombasa'],
])->save();
```

This can be useful if your business logic uses hard coded values, or constants.

Note that when an association has the same name as a virtual field,
the virtual field will overwrite the data prepared by the associated factory.

Similarly to `new()`, it is possible to inject a string into an associated factory:
```php
$country = CountryFactory::new()->with('Cities', 'Nairobi')->save();
```
or
```php
$country = CountryFactory::new()->with('Cities', ['Nairobi', 'Mombasa'])->save();
```

## Directional helpers: `for()` and `has()`

`with()` is the generic association attach. For the common cases — "this entity belongs to that one" and "this entity has these ones" — the directional helpers make intent explicit at the call site:

```php
// belongsTo: an article belongs to an author
$article = ArticleFactory::new()
    ->for(AuthorFactory::new(['name' => 'Mark']))
    ->save();

// has-many: an author has 3 articles
$author = AuthorFactory::new()
    ->has(ArticleFactory::new()->count(3))
    ->save();
```

Both methods auto-resolve which association to attach based on the target factory's table — no association name needed. If the parent table has more than one association pointing at the target table (e.g. `created_by` and `updated_by` both → Users), the call throws an "ambiguous association" exception so you don't accidentally attach to the wrong one. Use the underlying `with('Author', …)` form with the explicit association name when you need to disambiguate.

> Bake-generated `for*()` / `has*()` helpers emit `with('AssociationName', …)` rather than `for()` / `has()` for exactly this reason — the explicit alias is unambiguous at codegen time and survives later schema changes that introduce sibling associations.

`has()` accepts an optional `$pivot` array as the second parameter for habtm joins, populating the `_joinData` columns on the join row.

## `from()` — start from an existing entity

Use `from(EntityInterface)` when you already have an entity and want a factory backed by it:

```php
$article = $articlesTable->newEntity(['title' => 'Existing']);
$factory = ArticleFactory::from($article);
```

Unlike `state(EntityInterface)` (which extracts via `toArray()`), `from()` preserves the entity's identity, so `_accessible`/`_virtual`/source-alias stay intact.

### `from()` / `new($entity)` wrap exactly one entity

Combining a single injected entity with a count greater than 1 throws `RuntimeException`:

```php
// ✗ throws
ArticleFactory::from($article)->count(3)->buildMany();
ArticleFactory::new($article)->count(3)->buildMany();
ArticleFactory::new($article, 3)->buildMany();
```

A single entity cannot legitimately become N distinct entities — the factory would otherwise return N references to the same instance, mutated repeatedly by each iteration.

To produce N entities seeded from an existing one, extract its data and feed that through `new()` instead:

```php
// ✓ N distinct entities, all carrying $base's field values
$articles = ArticleFactory::new($base->toArray())->count(3)->buildMany();

// ✓ Vary attributes per row with sequence() / sequenceField() on top
$articles = ArticleFactory::new($base->toArray())
    ->count(3)
    ->sequenceField('slug', 'a', 'b', 'c')
    ->buildMany();
```

You can also pass a list of arrays to `new()` directly when each row needs its own data:

```php
$articles = ArticleFactory::new([
    ['title' => 'First'],
    ['title' => 'Second'],
    ['title' => 'Third'],
])->buildMany();
```

## Factory injection

When building associations, you may simply provide a factory as parameter. Example:

```php
$country = CountryFactory::new()->with('Cities', CityFactory::new()->threeCitiesAndFiveVillages())->save();
```
will provide a country associated with three cities and five villages.

## Entity injection

You may also inject an existing entity. The previous example would be now:
```php
$threeCitiesAndFiveVillages = CityFactory::new()->threeCitiesAndFiveVillages()->buildMany();
$country = CountryFactory::new()->with('Cities', $threeCitiesAndFiveVillages)->save();
```

You may also pass an array of factories:
```php
$threeCitiesAndFiveVillages = CityFactory::new()->threeCitiesAndFiveVillages()->buildMany();
$country = CountryFactory::new()->with('Cities', [
    CityFactory::new()->threeCitiesAndFiveVillages(),
    CityFactory::new()->capitalCity(),
])->save();
```
