---
title: Upgrading
description: Per-version upgrade notes
---

# Upgrading

This page collects breaking-change notes between minor versions. For migrating
from `vierge-noire/cakephp-fixture-factories`, see the [migration guide](migration.md).

## 1.3 → 1.4

### Symmetric persist/get API

`persist()` is now deprecated. Its return type is the union
`TEntity|iterable<TEntity>`, which means callers always have to narrow
before they can use the result. 1.4 introduces two typed replacements:

| Before (1.3.x)            | After (1.4.0)         | When to use                                  |
|---------------------------|-----------------------|----------------------------------------------|
| `persist()` (single row)  | `persistEntity()`     | Factory configured via `make()` or `make([...singleRow])` |
| `persist()` (multiple)    | `persistEntities()`   | `setTimes(n)`, `make([[...], [...]])`, multi-entity factories |

`persistEntity()` keeps a throw-if-multiple safety check; if the factory
ends up producing more than one entity, you get a clear runtime error
instead of a silently-narrowed return value. `persistEntities()` always
returns an `array<TEntity>`.

The in-memory side is now symmetric: `getEntity()` and `getEntities()`
are no longer marked deprecated. They form the non-persisted counterpart
to the persist pair.

All four methods are typed through the `TEntity` template on
`BaseFactory`, so an IDE resolves return types to the concrete entity
class once your factory declares the template parameter (see below).

`persist()` will be removed in v4.

#### `persist()` call sites

Replace each call site with `persistEntity()` or `persistEntities()` based
on how the factory was configured:

```diff
- $invoice = InvoiceFactory::make()->persist();
+ $invoice = InvoiceFactory::make()->persistEntity();

- $invoices = InvoiceFactory::make()->setTimes(5)->persist();
+ $invoices = InvoiceFactory::make()->setTimes(5)->persistEntities();
```

### Factory subclass annotations

To get sharp IDE autocomplete and PHPStan/Psalm type resolution on
`getEntity()`, `getEntities()`, `persistEntity()`, `persistEntities()`,
`getResultSet()` and `getPersistedResultSet()`, declare the template
parameter on each Factory subclass:

```php
/**
 * extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\Invoice>
 */
class InvoiceFactory extends BaseFactory
```

(The leading-backslash FQN form is the safest because it does not depend on
a `use` statement being present, and matches what
SlevomatCodingStandard's `FullyQualifiedClassNameInAnnotation` sniff
enforces.)

If your project already had hand-rolled `method` annotations like

```
method getEntity()
method getEntities()
method persist()
```

on each Factory subclass, replace each block with the single `extends`
line above. The IDE-resolved types are equivalent and the new
`persistEntity()` / `persistEntities()` methods are picked up
automatically.

### Bake

The `bake fixture_factory` template now emits the `extends` form by default;
freshly-baked factories require no manual annotation work.

### Migrating an existing project

The bake template only helps with new factories. For existing ones, two
paths:

1. **Bundled migration script** — a standalone PHP script that rewrites
   all factory subclasses under a given path in one pass:

   ```bash
   php vendor/dereuromark/cakephp-fixture-factories/bin/migrate-factory-annotations.php \
       tests/Factory
   ```

   You can pass multiple paths (e.g. for plugin factories under
   `plugins/MyPlugin/tests/Factory`). The script is idempotent — running
   it twice is a no-op. It removes legacy `method getEntity/getEntities/
   persist` blocks and inserts the canonical `extends` line, keeping
   any other docblock content intact.

2. **`FactoryAnnotatorTask`** *(if you also use
   `dereuromark/cakephp-ide-helper` 2.17 or newer)* — the task is
   auto-registered during plugin bootstrap, declares its own scan path
   (`tests/Factory/`) via `PathAwareClassAnnotatorTaskInterface`, and
   runs as part of the standard `bin/cake annotate classes` (or
   `annotate all`) command. Unlike the migration script it keeps your
   factory annotations correct over time, not just on the one-time
   upgrade.

After running either, verify with your usual quality gates (phpunit,
phpstan, phpcs).
