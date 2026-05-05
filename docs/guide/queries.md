# Queries


The fixture factories are closely related to the database. The package provides several methods to conveniently
run queries. All methods will by-pass the `beforeFind` event, enabling the direct inspection of your
test database.

These methods are meant to help performing assertions in the "Assert" part of your tests. Do not use the `::find()` method in the "Act" part of your tests, e.g. to test finders.

## `ArticleFactory::find()`

Returns a query on the table related to the given factory. Accepts the same parameters as the standard table `find()` method — see the [CakePHP query-builder docs](https://book.cakephp.org/5/en/orm/query-builder.html).

## `ArticleFactory::query()->count()`

Returns the number of rows in the factory's table.

## `ArticleFactory::get()`

Returns an entity by primary key. See the [CakePHP `get()` docs](https://book.cakephp.org/5/en/orm/retrieving-data-and-resultsets.html#getting-a-single-entity-by-primary-key) for the full signature.

## `ArticleFactory::firstOrFail()`

Returns the first entity matching the optional conditions, or throws if none exist. See the [CakePHP `firstOrFail()` docs](https://book.cakephp.org/5/en/orm/query-builder.html#getting-results).
