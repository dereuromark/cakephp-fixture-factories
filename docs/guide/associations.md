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

Both methods auto-resolve which association to attach based on the target factory's table — no association name needed.

Pass an explicit `$alias` as the second argument when this factory's table declares **more than one** association pointing at the same target — the auto-resolver cannot pick a single one and would throw without the alias:

```php
// Authors belongsTo Address AND BusinessAddress (both → Addresses):
$author = AuthorFactory::new()
    ->for(AddressFactory::new(['street' => 'Home']),   'Address')
    ->for(AddressFactory::new(['street' => 'Office']), 'BusinessAddress')
    ->save();

// Countries hasMany Cities AND VirtualCities (both → Cities):
$country = CountryFactory::new()
    ->has(CityFactory::new()->count(3), 'Cities')
    ->has(CityFactory::new()->count(2), 'VirtualCities')
    ->save();
```

`has()` also accepts an optional `$pivot` array as a third argument for belongsToMany joins, populating the `_joinData` columns on the join row:

```php
$article = ArticleFactory::new()
    ->has(AuthorFactory::new()->count(2), 'Authors', ['featured' => true])
    ->save();
```

### Disambiguating `for()` and `has()`

When the parent table declares **more than one** association pointing at the target table and you don't pass `$alias`, the auto-resolver cannot pick a single one and throws — for example, an `Authors` table with both `Address` and `BusinessAddress` belonging to `Addresses`:

```
AuthorFactory::for(AddressFactory::new()) cannot resolve a unique belongsTo —
`Authors` declares 2 associations targeting `Addresses`:
  - Address         (foreign key: address_id)
  - BusinessAddress (foreign key: business_address_id)

Use the explicit form to disambiguate:
  AuthorFactory::new()->with('Address',         AddressFactory::new())
  AuthorFactory::new()->with('BusinessAddress', AddressFactory::new())
```

**Quick fix at the call site** — fall back to the lower-level `with('AliasName', $factory)` form. Both `with()` lines in the exception message are paste-ready:

::: code-group
```php [Pick the alias you want]
$author = AuthorFactory::new()
    ->with('Address', AddressFactory::new(['street' => 'Home']))
    ->save();
```

```php [Or stack multiple aliases]
$author = AuthorFactory::new()
    ->with('Address', AddressFactory::new(['street' => 'Home']))
    ->with('BusinessAddress', AddressFactory::new(['street' => 'Office']))
    ->save();
```
:::

**Long-term pattern** — for any factory whose target table has more than one association in or out, define named wrapper methods on the factory itself. The bake command generates these automatically when you pass `--methods`:

```bash
bin/cake bake fixture_factory Authors --methods
```

```php
class AuthorFactory extends BaseFactory
{
    public function forAddress($parameter = null): static
    {
        return $this->with('Address', AddressFactory::new($parameter));
    }

    public function forBusinessAddress($parameter = null): static
    {
        return $this->with('BusinessAddress', AddressFactory::new($parameter));
    }
}
```

Call sites then read like the directional API again, with the alias baked into the method name:

```php
$author = AuthorFactory::new()
    ->forAddress(['street' => 'Home'])
    ->forBusinessAddress(['street' => 'Office'])
    ->save();
```

::: tip
See [Best Practices — directional helper methods](best-practices#do-add-directional-helper-methods-for-every-association) for the broader recommendation. The factory class is the right home for schema-coupled knowledge: it already knows it is the `AuthorFactory`; let it own the `Address` / `BusinessAddress` choice too rather than scattering inline alias strings across hundreds of call sites.
:::

::: tip Why bake emits `with()` and not `for()`
Bake's `--methods` output uses `with('AliasName', …)` rather than `for()` / `has()` even when the relation is unambiguous today, because the alias is unambiguous at codegen time and survives later schema changes that introduce sibling associations. If you reach for `with()` to disambiguate at a call site, you're using the same form bake would have generated.
:::

## Recycling shared parents: `recycle()`

When the same parent should appear on **multiple branches** of an association tree, threading the parent entity through every branch by hand is noisy and easy to forget. `recycle($entity)` registers an already-built entity to be reused wherever any `belongsTo` in the build graph targets the same source table.

```php
$author = AuthorFactory::new()->save();

// Every Article *and* every Comment under each Article uses $author
// (assuming Article belongsTo Author and Comment belongsTo Author):
$articles = ArticleFactory::new()
    ->count(5)
    ->with('Comments[3]')
    ->recycle($author)
    ->saveMany();
```

Without `recycle()`, that same build would silently create up to 20 distinct Author rows.

### How it matches

`recycle()` is keyed by **table name**. It substitutes whenever a `belongsTo` association in the build graph targets the same table as the recycled entity AND that branch's child factory has no explicit customization. The recycled entity must already be **persisted** (i.e. you've called `->save()`).

Recycle is intentionally narrow:

- Recycled entities must be saved. An entity from `Factory::new(...)->build()` (built in memory, not in the database) is rejected — recycle's fast path skips the child factory's persistence pipeline, so unsaved entities would never get a primary key. Pass them via `with('Alias', $entity)` instead if you need that.
- Recycle does not override branches the user customized via `with('Alias', $entity)` (per-alias entity) or `with('Alias', Factory::new()->forX())` (chained child factory). Those calls express explicit per-branch intent and win.
- `configure()` defaults (the chains baked into `forX()` / `hasX()` helpers and registered before user input) do **not** count as "customized" — recycle still applies to them.

```php
$country = CountryFactory::new()->save();

CityFactory::new()
    ->count(5)
    ->forCountries()       // each city would normally get a fresh Country
    ->recycle($country)
    ->saveMany();          // all 5 cities share $country instead
```

### Propagation through nested factories

Recycles flow down the build graph. The recycled map is inherited by every child factory that runs as part of the same build, so a recycle set on the root applies anywhere in the tree:

```php
$country = CountryFactory::new()->save();

// Address belongsTo City; City belongsTo Country. Recycle the Country
// at the root and every nested City reuses it:
AddressFactory::new()
    ->count(3)
    ->with('City', CityFactory::new()->forCountries())
    ->recycle($country)
    ->saveMany();
```

### Multiple recycles

`recycle()` is variadic and chainable. Each call merges into the recycle map (last call wins for the same target table):

```php
$country = CountryFactory::new()->save();
$category = CategoryFactory::new()->save();

ArticleFactory::new()
    ->count(10)
    ->with('Category')
    ->with('Author.Country')
    ->recycle($country, $category)
    ->saveMany();
```

### Multiple aliases targeting the same table

If a factory declares two `belongsTo` aliases pointing at the same target table (e.g. `Authors belongsTo Address` and `BusinessAddress`, both targeting `Addresses`), `recycle()` substitutes **both** branches with the recycled entity. If you need per-alias control, use `with('AliasName', $entity)` directly instead:

```php
$home   = AddressFactory::new()->save();
$office = AddressFactory::new()->save();

// Per-alias control via explicit with():
AuthorFactory::new()
    ->with('Address', $home)
    ->with('BusinessAddress', $office)
    ->save();
```

### When `recycle()` doesn't help

- The build graph has no registered `belongsTo` to the recycled entity's table — recycle is a silent no-op.
- You need different parents on different branches — use `with('AliasName', $entity)` per branch.
- You want to reuse a `hasMany` or `belongsToMany` collection — recycle only substitutes single-row `belongsTo` parents. Use `with()` with concrete entities for the many side.

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
