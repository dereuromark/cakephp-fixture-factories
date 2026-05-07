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
