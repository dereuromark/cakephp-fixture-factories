---
title: Upgrading
description: Per-version upgrade notes
---

# Upgrading

This page collects breaking-change notes between minor versions. For migrating
from `vierge-noire/cakephp-fixture-factories`, see the [migration guide](migration.md).

## v2 migration helpers

Version 2 targets projects already on `1.4.x`.
If you're still on `1.3.x`, upgrade to `1.4.x` first, then move to v2.

### Behavior changes since 1.4.x

- **`setGenerator()` now scopes to the calling factory by default.** The `FixtureFactories.instanceLevelGenerator` config defaults to `true` in v2 (was `false` in 1.x). Calls like `ArticleFactory::new()->setGenerator('dummy')` no longer mutate the global default — they return a clone with the override scoped to that factory. If you relied on the global mutation, either set `Configure::write('FixtureFactories.instanceLevelGenerator', false)` in your test bootstrap to restore the 1.x behavior, or use the explicit static `BaseFactory::setDefaultGenerator($type)` instead.
- **`setDefaultTemplate()` / `setDefaultData()` are no longer wired up.** v2 only consults `definition(GeneratorInterface $generator): array` on the factory class. Run the bundled Rector config (below) to migrate; otherwise factories silently produce empty data.
- **The standalone `bin/migrate-factory-annotations.php` shim is gone.** Annotation upkeep now lives entirely in the IDE-helper integration: install `dereuromark/cakephp-ide-helper` and run `bin/cake annotate classes` (or `annotate all`) — `FactoryAnnotatorTask` is auto-registered when the plugin boots.
- **The bake command's default-data guesser was extracted into a dedicated class.** The bundled `BakeFixtureFactoryCommand` no longer carries the protected `$map` property or the `guessDefault()` method — both moved to `CakephpFixtureFactories\Codegen\DefaultDataGuesser`. Subclasses that overrode the old hooks should either swap in a custom guesser by overriding `getDefaultDataGuesser()` in the command subclass, or use `DefaultDataGuesser::setMap()` / `mergeMap()` to inject project-specific column conventions. The Configure keys `FixtureFactories.defaultDataMap` and `FixtureFactories.columnPatterns` still work unchanged for the common case.
- **`FactoryTransactionStrategy` is eager by default again.** v1.3.0 (PR #23) flipped the strategy from eager to lazy: a connection only joined the rollback set after a Factory persisted on it. That silently broke tests that mixed Factory build with direct `$table->save($entity)` (the testFind / testValidationDefault pattern) — the direct save ran outside any transaction and leaked across test methods. v2 restores the eager default: `setupTest()` opens a transaction on the primary test connection (`test` by default; configurable via the `protected string $primaryConnection` property) up-front. Additional connections are still tracked lazily via `ensureTransaction()` from `BaseFactory::save()` / `saveMany()`, so multi-database setups still skip transactions on connections they never write to. Suites that explicitly want the 1.3.0+ lazy behaviour opt in via `LazyFactoryTransactionStrategy::class` (whole suite) or `LazyTransactionTrait` (per test class).

For the v2 step, the package ships a Rector config to help with the mechanical API rename work:

```bash
vendor/bin/rector process tests --config rector.php
```

The bundled rules cover the safe, mechanical call-site changes:

- `Factory::make(...)` to `Factory::new(...)`
- `Factory::make($data, $n)` to `Factory::new($data)->count($n)`
- nested helper-body calls such as `EventFactory::make($parameters, $n)`
- `setDefaultTemplate()` wrappers to `definition(GeneratorInterface $generator)`
- `getEntity()` to `build()`
- `getEntities()` to `buildMany()`
- `persistEntity()` to `save()`
- `persistEntities()` to `saveMany()`
- `patchData(...)` to `state(...)` (in factory helper methods such as `asAdmin()`)
- static query helpers like `Factory::find()` to `Factory::query()`
- `Factory::get($id, $options)` to `Factory::table()->get($id, $options)`

It intentionally does **not** rewrite deprecated `persist()` calls, because that return type is shape-dependent and needs a human choice between `save()` and `saveMany()`.

Typical before/after replacements look like this:

```diff
- $article = ArticleFactory::make(['title' => 'Foo'])->persistEntity();
+ $article = ArticleFactory::new(['title' => 'Foo'])->save();

- $articles = ArticleFactory::make()->setTimes(3)->persistEntities();
+ $articles = ArticleFactory::new()->count(3)->saveMany();

- $article = ArticleFactory::get($id, ['contain' => ['Authors']]);
+ $article = ArticleFactory::table()->get($id, ['contain' => ['Authors']]);

- $published = ArticleFactory::find('published')->all();
+ $published = ArticleFactory::query()->find('published')->all();
```

Custom factory helpers that wrap field overrides also flip from
`patchData()` to `state()`:

```diff
 public function asAdmin(): static
 {
-    return $this->patchData(['role_id' => self::ROLE_ADMIN]);
+    return $this->state(['role_id' => self::ROLE_ADMIN]);
 }
```

Factory subclass `@extends BaseFactory<TEntity>` annotations are expected to already be in place from the `1.4.x` upgrade step.

## 1.3 → 1.4

These notes are kept here as historical context for the required pre-v2 upgrade path. Complete the `1.4.x` migration before adopting v2.

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

`persist()` was removed in v2. Use `save()` or `saveMany()` instead.

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
`getEntity()`, `getEntities()`, `persistEntity()` and
`persistEntities()`, declare the template parameter on each Factory
subclass:

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

The bake template only helps with new factories. For existing ones, use
**`FactoryAnnotatorTask`** *(if you also use
   `dereuromark/cakephp-ide-helper` 2.17 or newer)* — the task is
   auto-registered during plugin bootstrap, declares its own scan path
   (`tests/Factory/`) via `PathAwareClassAnnotatorTaskInterface`, and
   runs as part of the standard `bin/cake annotate classes` (or
   `annotate all`) command. It keeps your factory annotations correct
   over time, not just on a one-time upgrade.

After updating annotations, verify with your usual quality gates (phpunit,
phpstan, phpcs).
