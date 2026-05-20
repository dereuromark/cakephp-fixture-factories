# Queries


The fixture factories are closely related to the database. The package provides several methods to conveniently
run queries. All methods will by-pass the `beforeFind` event, enabling the direct inspection of your
test database.

These methods are meant to help performing assertions in the "Assert" part of your tests. Do not use `::query()` in the "Act" part of your tests, e.g. to test finders.

## `ArticleFactory::query()`

Returns a query on the table related to the given factory. From there, use the standard CakePHP query API:

```php
ArticleFactory::query()->find('published');
ArticleFactory::query()->find('list');
ArticleFactory::query()->where(['title LIKE' => '%Cake%']);
```

See the [CakePHP query-builder docs](https://book.cakephp.org/5/en/orm/query-builder.html).

## `ArticleFactory::query()->count()`

Returns the number of rows in the factory's table.

## Fetching by primary key

Use the query surface when you want finder-style conditions:

```php
ArticleFactory::query()->where(['id' => $id])->firstOrFail();
```

If you specifically want CakePHP's table `get()` semantics, use the table accessor instead:

```php
ArticleFactory::table()->get($id);
ArticleFactory::table()->get($id, contain: ['Authors']);
```

This is the direct replacement for the old deprecated `ArticleFactory::get(...)` helper. It keeps primary-key fetches on the Cake table API, while `query()` remains the read/query surface for finder chains.

## `ArticleFactory::query()->firstOrFail()`

Returns the first entity matching the optional conditions, or throws if none exist. See the [CakePHP `firstOrFail()` docs](https://book.cakephp.org/5/en/orm/query-builder.html#getting-results).

## Expressive database assertions: `TableAssertionsTrait`

For the "Assert" half of tests, the plugin ships an opt-in trait that wraps the most common `Factory::query()` checks with sharper failure messages. The trait stays on the v2 design line — no static read surface is added to `BaseFactory`; it just composes over `Factory::query()` internally.

```php
use CakephpFixtureFactories\TestSuite\TableAssertionsTrait;

class ArticlesControllerTest extends AppTestCase
{
    use TableAssertionsTrait;

    public function testCreate(): void
    {
        $this->post('/articles', ['title' => 'Hello', 'body' => '…']);

        $this->assertTableCount(ArticleFactory::class, 1);
        $this->assertTableHas(ArticleFactory::class, ['title' => 'Hello']);
        $this->assertTableMissing(ArticleFactory::class, ['status' => 'spam']);
    }
}
```

### Methods

- `assertTableHas(string $factoryClass, array $criteria, ?string $message = null)` — at least one row matches.
- `assertTableMissing(string $factoryClass, array $criteria, ?string $message = null)` — zero rows match.
- `assertTableCount(string $factoryClass, int $expected, array $criteria = [], ?string $message = null)` — exact row count (optionally narrowed by criteria).
- `assertTableEmpty(string $factoryClass, ?string $message = null)` — table has no rows.
- `assertEntityExists(EntityInterface $entity, ?string $factoryClass = null, ?string $message = null)` — the entity is still in the database (by primary key).
- `assertEntityMissing(EntityInterface $entity, ?string $factoryClass = null, ?string $message = null)` — the entity is no longer in the database.

> [!IMPORTANT]
> `assertEntityMissing()` refuses entities that were never persisted — both the "no primary key" case and the application-assigned-PK case (UUID / string IDs) where the entity carries a PK at build time but was never written. The guard checks `isNew()` first and the PK as a fallback. Save the entity (then delete it) before asserting it is missing.

The optional `$factoryClass` argument on the entity assertions scopes the lookup to that factory's table. Use it when several factory variants share a bare table alias on different connections — without it, the entity's `getSource()` is consulted, which may resolve to whichever factory variant most-recently registered with the locator.

`$criteria` is passed straight to `Factory::query()->where(...)`, so the same operator forms work — e.g. `['title LIKE' => '%Cake%']`, `['status IN' => ['draft', 'published']]`, `['author_id' => $author->id]`.

### Failure messages

The trait emits explicit messages so you don't have to guess at a failure:

```
Expected ArticleFactory to have at least one row matching {title: 'Hello'}, found none.

Expected 5 rows in CountryFactory matching {name: 'France'}, found 3.

Expected entity `App\Model\Entity\Article` (PK: id=42) to exist in the database, but it does not.
```

Pass an optional `$message` arg to append project-specific context.
