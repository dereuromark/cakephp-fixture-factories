---
title: Upgrading
description: Per-version upgrade notes
---

# Upgrading

This page collects breaking-change notes between minor versions. For migrating
from `vierge-noire/cakephp-fixture-factories`, see the [migration guide](migration.md).

## Since `2.0.0-beta.3`

Two breaking changes and several additions. The package is still pre-stable so
these moves are intentional — getting the API shape right before tagging stable
is worth a one-time mechanical migration.

### Breaking: `sequence()` callable now takes a single `Sequence` arg

The 3-arg positional form `($factory, $generator, int $index)` is gone. New
callables receive a single `Sequence` value object that bundles all the
context: index, position, total, isFirst, isLast, factory, generator.

```diff
 ArticleFactory::new()
     ->count(5)
-    ->sequence(fn ($factory, $generator, int $i) => ['rank' => $i])
+    ->sequence(fn (Sequence $s) => ['rank' => $s->index])
     ->saveMany();
```

Every member of `Sequence` is a `public readonly` property — no method calls,
all IDE-autocompleted:

| Old positional arg | New on `$s`     |
|--------------------|-----------------|
| `$factory`         | `$s->factory`   |
| `$generator`       | `$s->generator` |
| `$index`           | `$s->index`     |
| —                  | `$s->position` (1-based) |
| —                  | `$s->total`     |
| —                  | `$s->isFirst`   |
| —                  | `$s->isLast`    |

Import: `use CakephpFixtureFactories\Factory\Sequence;`. No Rector rule is
shipped for this rewrite — the variable-name shape is too consumer-specific.
A repo-wide grep for `->sequence(` is the quickest manual migration.

Array-state and entity-state sequence forms (`sequence(['a' => 1], ['a' => 2])`)
are unchanged — only the callable form's signature shifted.

### Breaking: `has()` pivot data moves to the 3rd argument

`has()` gained an optional `$alias` parameter as its second argument to
disambiguate associations on multi-alias schemas (see "Alias overload" below).
The pivot array moved one slot down.

```diff
- $author = AuthorFactory::new()->has($articleFactory, ['featured' => true])->save();
+ $author = AuthorFactory::new()->has($articleFactory, null, ['featured' => true])->save();
```

Or use a named argument to avoid the positional-`null`:

```php
$author = AuthorFactory::new()->has($articleFactory, pivot: ['featured' => true])->save();
```

While checking your call sites, also note that `has($f, $pivot)` previously
patched `_joinData` via the marshaller's `associated` config only — pivot
values **silently dropped** at save time. The new `has()` patches `_joinData`
directly onto every built child entity, so pivot values now actually land on
the join row. If you depended on the old no-op behavior, audit those call
sites.

### Alias overload on `for()` / `has()`

When the source table declares more than one association pointing at the
same target, pass an explicit alias instead of dropping down to `with()`:

```php
AuthorFactory::new()
    ->for(AddressFactory::new(['street' => 'Home']),   'Address')
    ->for(AddressFactory::new(['street' => 'Office']), 'BusinessAddress')
    ->save();

CountryFactory::new()
    ->has(CityFactory::new()->count(3), 'Cities')
    ->has(CityFactory::new()->count(2), 'VirtualCities')
    ->save();
```

Single-argument `for($factory)` / `has($factory)` keeps working unchanged
when the alias is unambiguous. When an alias is given, a direction guard
runs: `for('hasMany-alias')` and `has('belongsTo-alias')` fail fast with a
clear error instead of silently miswiring the graph. Unknown aliases also
surface a paste-ready list of valid aliases on the source table.

### New: `recycle()` for shared parents

```php
$author = UserFactory::new()->save();

ArticleFactory::new()
    ->count(5)
    ->with('Comments[3]')
    ->recycle($author)   // every Article AND each nested Comment reuses $author
    ->saveMany();
```

Closes the silent N× duplicate-parent gap when the same parent appears on
multiple branches of an association tree. See
[Associations → Recycling shared parents](associations.md#recycling-shared-parents-recycle) for the full contract.

### New: `TableAssertionsTrait` for database-state assertions

Opt-in trait that wraps the common `Factory::query()` checks with sharper
failure messages:

```php
use CakephpFixtureFactories\TestSuite\TableAssertionsTrait;

class ArticlesControllerTest extends AppTestCase
{
    use TableAssertionsTrait;

    public function testCreate(): void
    {
        $this->post('/articles', ['title' => 'Hello']);

        $this->assertTableCount(ArticleFactory::class, 1);
        $this->assertTableHas(ArticleFactory::class, ['title' => 'Hello']);
        $this->assertTableMissing(ArticleFactory::class, ['status' => 'spam']);
    }
}
```

Six methods: `assertTableHas`, `assertTableMissing`, `assertTableCount`,
`assertTableEmpty`, `assertEntityExists`, `assertEntityMissing`. See
[Queries → Expressive database assertions](queries.md#expressive-database-assertions-tableassertionstrait).

### New: `Story` scenario with named entity pools

Extend the new `Story` abstract instead of implementing `FixtureScenarioInterface`
directly when your scenario seeds data and the test wants to sample from it:

```php
use CakephpFixtureFactories\Scenario\Story;

class BlogStory extends Story
{
    protected function build(): void
    {
        $this->addToPool('authors', UserFactory::new()->count(10)->saveMany());
        ArticleFactory::new()->count(50)->saveMany();
    }
}

// In the test:
$story = $this->loadFixtureScenario(BlogStory::class);
$author = $story->getRandom('authors');
```

Existing `FixtureScenarioInterface` implementations keep working unchanged.
Short-name loading (`loadFixtureScenario('BlogStory')`) now tries the
verbatim class name as a fallback when no `*Scenario` class exists.

### New: `bake fixture_factory --all-fields`

Emits defaults for **every** non-PK, non-FK column — including nullable
columns and columns with a DB default — instead of only required columns:

```bash
bin/cake bake fixture_factory Articles --all-fields
```

Foreign keys stay excluded regardless so the baked factory keeps pushing
related rows through `with()` / `for()` / `has()`.

## v2 migration helpers

Version 2 targets projects already on `1.4.x`.
If you're still on `1.3.x`, upgrade to `1.4.x` first, then move to v2.

### Behavior changes since 1.4.x

- **`setGenerator()` now scopes to the calling factory by default.** The `FixtureFactories.instanceLevelGenerator` config defaults to `true` in v2 (was `false` in 1.x). Calls like `ArticleFactory::new()->setGenerator('dummy')` no longer mutate the global default — they return a clone with the override scoped to that factory. If you relied on the global mutation, either set `Configure::write('FixtureFactories.instanceLevelGenerator', false)` in your test bootstrap to restore the 1.x behavior, or use the explicit static `BaseFactory::setDefaultGenerator($type)` instead.
- **`setDefaultTemplate()` / `setDefaultData()` are no longer wired up.** v2 only consults `definition(GeneratorInterface $generator): array` on the factory class. Run the bundled Rector config (below) to migrate; otherwise factories silently produce empty data.
- **The standalone `bin/migrate-factory-annotations.php` shim is gone.** Annotation upkeep now lives entirely in the IDE-helper integration: install `dereuromark/cakephp-ide-helper` and run `bin/cake annotate classes` (or `annotate all`) — `FactoryAnnotatorTask` is auto-registered when the plugin boots.
- **The bake command's default-data guesser was extracted into a dedicated class.** The bundled `BakeFixtureFactoryCommand` no longer carries the protected `$map` property or the `guessDefault()` method — both moved to `CakephpFixtureFactories\Codegen\DefaultDataGuesser`. Subclasses that overrode the old hooks should either swap in a custom guesser by overriding `getDefaultDataGuesser()` in the command subclass, or use `DefaultDataGuesser::setMap()` / `mergeMap()` to inject project-specific column conventions. The Configure keys `FixtureFactories.defaultDataMap` and `FixtureFactories.columnPatterns` still work unchanged for the common case.
- **`FactoryTransactionStrategy` is eager by default again.** v1.3.0 (PR #23) flipped the strategy from eager to lazy: a connection only joined the rollback set after a Factory persisted on it. That silently broke tests that mixed Factory build with direct `$table->save($entity)` (the testFind / testValidationDefault pattern) — the direct save ran outside any transaction and leaked across test methods. v2 restores the eager default: `setupTest()` opens a transaction on the primary test connection (`test` by default; configurable via the `protected string $primaryConnection` property) up-front. Additional connections are still tracked lazily via `ensureTransaction()` from `BaseFactory::save()` / `saveMany()`, so multi-database setups still skip transactions on connections they never write to. Suites that explicitly want the 1.3.0+ lazy behaviour opt in via `LazyFactoryTransactionStrategy::class` (whole suite) or `LazyTransactionTrait` (per test class).
- **DummyGenerator backend now requires `johnykvsky/dummygenerator:^0.2.1`** (was `^0.1.0 || ^0.2.0`). v0.1.x is removed entirely — the deprecation notice the adapter used to emit on every test boot is gone, the legacy `DefinitionContainerBuilder` code path with it. The v2 adapter only constructs `DummyGenerator::create()` and calls `withDefinition(RandomizerInterface::class, new XoshiroRandomizer($seed))` directly. Tied to that: v2 now forwards the `optional()` float weight to `boolean()` directly (added in v0.2.1), so weights below 0.005 — e.g. `optional(0.001)` for 0.1% chance — fire at the requested rate instead of silently rounding to 0%. Projects on `^0.2.0` need to bump to `^0.2.1` (a patch release); projects still on `^0.1.x` need to upgrade to `^0.2.1`.
- **`enumElement()` alias is removed.** The Faker-specific name was a back-compat alias for `enumCase()`; both adapters now expose only `enumCase()` (returns the case) and `enumValue()` (returns the backed-enum scalar). The `trigger_error(E_USER_DEPRECATED)` the dummy adapter used to emit on every `enumElement()` call under `debug = true` is gone with it. Callers that still use `enumElement(EnumClass::class)` should rename to `enumCase(EnumClass::class)` — the bundled rector config does not rewrite this rename, so it's a manual one-line change per call site.

For the v2 step, the package ships a Rector config to help with the mechanical API rename work:

```bash
vendor/bin/rector process tests --config vendor/dereuromark/cakephp-fixture-factories/rector.php
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
- Faker-style property access on the generator to a method call: `$generator->name` to `$generator->name()` (also covers `optional()` / `unique()` chains and `$this->getGenerator()->name`)

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

### If commit-phase tests block the upgrade

The recommended `FactoryTransactionStrategy` is still the best default for v2, but some older suites rely on real commit behavior during the test itself. If your tests depend on `Model.afterSaveCommit`, commit-triggered listeners/behaviors, or rows being durably written before assertions run, CakePHP's `Eager` fixture strategy can be a practical temporary fallback.

That is mainly a migration-pressure valve: it lets you move to the v2 factory API first and defer the heavier refactor of commit-sensitive tests. The tradeoff is that you give up the transaction-based cleanup and automatic unique-state reset provided by `FactoryTransactionStrategy`.

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
