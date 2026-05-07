---
title: Troubleshooting
description: Common gotchas and how to fix them
---

# Troubleshooting

## `OverflowException` from `unique()`

```
OverflowException: Maximum retries of 10000 reached without finding a unique value
```

**Cause:** the unique generator state accumulates across tests instead of resetting between them.

**Fix:** switch to the [FactoryTransactionStrategy](setup#factory-transaction-strategy-recommended) — it resets unique state automatically. If you cannot use it, manually call `CakeGeneratorFactory::clearInstances()` and `BaseFactory::resetDefaultGenerator()` between tests.

## `Faker library is not installed`

```
Faker library is not installed. Please install it using: `composer require --dev fakerphp/faker`
```

**Cause:** the default generator is `faker`, but the package isn't installed.

**Fix:** either install Faker (`composer require --dev fakerphp/faker`) or switch the generator type:

```php
Configure::write('FixtureFactories.generatorType', 'dummy');
```

See [Generators](generators) for the comparison.

## `DummyGenerator library is not installed`

Same shape as the Faker error. Install with `composer require --dev johnykvsky/dummygenerator` (requires PHP 8.3+) or switch back to `'faker'`.

## Method works on one generator, not the other

Most methods are shimmed for compatibility, but a handful differ. Check the [method compatibility table](generators#_3-method-compatibility) before using a generator-specific method.

## Test data leaks between tests

Symptoms: prior test's `Authors` rows show up in the next test's queries.

**Cause:** no fixture strategy, or `TruncateStrategy` is failing silently because of FK constraints.

**Fix:** use [FactoryTransactionStrategy](setup#factory-transaction-strategy-recommended). It rolls back via transaction so FKs never fight you, and it captures non-factory writes too.

## Validation errors when persisting

Factories deactivate validation by default. If you're seeing rule or validation failures from `save()` / `saveMany()`, something is overriding that — check whether you've set `$marshallerOptions` or `$saveOptions` on the factory.

To re-enable validation deliberately:

```php
protected array $marshallerOptions = ['validate' => true];
protected array $saveOptions = ['checkRules' => true];
```

## Schema is out of date

Symptoms: missing columns, `SQLSTATE[42S22]`, etc.

**Fix:** run your migrations against the test DB. With the [CakePHP Migrations plugin](https://book.cakephp.org/migrations/5/index.html#using-migrations-for-tests), the `Migrator` tool keeps the test schema in sync automatically.

If you've manually edited an already-applied migration file, the test DB still has the old schema and migrations won't re-run that file. Wipe and re-migrate:

```bash
# Provided by the Setup plugin (dereuromark/cakephp-setup)
bin/cake db wipe -c test
bin/cake migrations migrate -c test
```

Without the Setup plugin, drop the test database manually (or empty it via your DB client) and re-run migrations. See [dereuromark/cakephp-setup](https://github.com/dereuromark/cakephp-setup) for the `db wipe` command.

## Bake doesn't generate what I expected

The bake command uses `defaultDataMap` and `columnPatterns` to choose generator methods per column. If a column is filled with the wrong helper, teach the bake command via [configuration](/reference/configuration#defaultdatamap):

```php
'columnPatterns' => [
    '/^phone/' => 'phoneNumber()',
    '/^zip/'   => 'postcode()',
],
```

Then re-run `bin/cake bake fixture_factory <Model> --force`.

## `setGenerator()` affects unrelated factories

This only happens if you disabled instance-level generators. Re-enable them to keep `setGenerator()` scoped to one factory instance:

```php
Configure::write('FixtureFactories.instanceLevelGenerator', true);
```

See [Fixture Factories — instance-level generators](factories#instance-level-generators) for details.

## Ambiguous association

`for()` and `has()` auto-resolve which association to attach by looking at the target factory's table. When the parent table declares more than one association pointing at that target table — for example a `Messages` table with both `Sender` and `Recipient` belonging to `Users`, or an `Authors` table with both `Address` and `BusinessAddress` belonging to `Addresses` — the resolver cannot pick a single one and throws:

```
MessageFactory::for(UserFactory::new()) cannot resolve a unique belongsTo —
`Messages` declares 2 associations targeting `Users`:
  - Sender    (foreign key: sender_id)
  - Recipient (foreign key: recipient_id)

Use the explicit form to disambiguate:
  MessageFactory::new()->with('Sender',    UserFactory::new())
  MessageFactory::new()->with('Recipient', UserFactory::new())
```

The fix is to use the lower-level `with('AliasName', $factory)` form with the explicit association alias instead of the directional helper. Both `with()` lines in the message above are paste-ready — pick the one that matches the relation you want to populate.

This is also why bake-generated `for*()` / `has*()` helpers emit `with('AliasName', …)` rather than `for()` / `has()`: the alias is unambiguous at codegen time and survives later schema changes that introduce sibling associations.

See [Associations — directional helpers](associations#directional-helpers-for-and-has) for the full design.
