---
title: What's new in v2
description: A tour of the new shape of CakePHP Fixture Factories 2.0
---

# What's new in v2

A guided tour of the v2.0 surface. For the per-version mechanical migration
notes (renames, removed methods, Rector config), see the
[upgrading guide](upgrading.md). This page is the **why and the wow** — what
v2 lets you write that v1 didn't.

## TL;DR

| Theme | What it changed |
| --- | --- |
| [Required parents](#withrequiredparents) | One call composes every NOT NULL belongsTo parent chain |
| [`recycle()`](#recycle) | Share one parent across an entire batch |
| [`for()` / `has()` alias overload](#for-has-alias-overload) | Explicit per-alias composition with target-type guard |
| [`Sequence` context object](#sequence-context-object) | Per-row context (`$s->index`, `isFirst`, `isLast`, `factory`, `generator`) inside `sequence()` callables |
| [`Story` scenarios](#story-scenarios) | Foundry-style entity pools with `getRandom()` / `getRandomSet()` |
| [`TableAssertionsTrait`](#tableassertionstrait) | Expressive database-state assertions with sharp failure messages |
| [Eager transaction strategy by default](#eager-transaction-strategy-restored) | Mixed direct-save + factory-save tests stay isolated again |
| [`strictDefinition` detector](#strictdefinition-fk-in-definition-detector) | Catches the silent "FK in `definition()`" footgun before it ships |
| [Bake quality](#bake-quality) | TimestampBehavior-managed columns are no longer baked, custom generator alias loads detected |

## `withRequiredParents()`

The single biggest ergonomic win of v2. Composes every belongsTo parent the
root table *requires* — every NOT NULL FK — recursively, in one call:

``` php
// Tables: Authors belongsTo Address (NOT NULL); Address belongsTo City (NOT NULL);
//         City belongsTo Country (NOT NULL).
// One call satisfies the whole chain.
$author = AuthorFactory::new()->withRequiredParents()->save();

// Composes with recycle() for shared-parent batches:
$country = CountryFactory::new()->save();
$authors = AuthorFactory::new()
    ->count(50)
    ->withRequiredParents()
    ->recycle($country) // all 50 authors share this one Country
    ->saveMany();
```

The detector reads the schema; it only auto-composes the cases it can resolve
unambiguously (single scalar NOT NULL FK). Composite-key,
`foreignKey => false`, and nullable FKs aren't auto-resolved by design.

Three protected hooks let factory classes shape their required-parent set:

- `requiredParentAssociations()` — opt in extras (typically a nullable
  single-scalar FK the factory wants composed regardless).
- `excludedRequiredParentAssociations()` — opt out specific aliases at the
  class level. Wins over both auto-detection and the additive hook.
- `allowedForeignKeysInDefinition()` — exempts specific FK columns from the
  `strictDefinition` deprecation (rare, transitional).

Per-call `$except` and `$maxDepth` arguments cover the one-off cases:

``` php
$author = AuthorFactory::new()
    ->withRequiredParents(except: ['Address'], maxDepth: 2)
    ->with('Address', $myAddress)
    ->save();
```

See the [Required parents guide](required-parents.md) for the full contract,
including the pinned-FK semantics, cycle detection, and the strict mode for
catching a depth cap that leaves a NOT NULL FK unsatisfied.

## `recycle()`

Reuse a saved entity wherever any `belongsTo` in the build graph targets the
same table — substitutes at *every depth*:

``` php
$country = CountryFactory::new()->save();
AddressFactory::new()
    ->count(3)
    ->with('City', CityFactory::new()->forCountries())
    ->recycle($country) // every nested City reuses this Country
    ->saveMany();
```

Variadic and chainable; substitutes through hasMany / belongsToMany edges
*into* sibling belongsTo branches; refuses ambiguous same-source duplicates
in a single call. See the [recycle section](associations.md#recycling-shared-parents-recycle)
of the associations guide.

## `for()` / `has()` alias overload

Cleaner composition when a table has multiple associations targeting the
same model, with a target-type guard that catches misuse at compose time:

``` php
// Disambiguates: Addresses belongsTo City AND ShippingCity
$order = OrderFactory::new()
    ->for(CityFactory::new(), 'ShippingCity')   // explicit alias
    ->has(LineItemFactory::new()->count(3))     // alias auto-resolved
    ->save();

// has() now also takes pivot data for belongsToMany joins:
$author = AuthorFactory::new()
    ->has(ArticleFactory::new()->count(2), 'Articles', ['featured' => true])
    ->save();
```

`has()` rejects pivot data on non-BTM aliases (was silently dropped in v1).
The alias overload's target-type guard catches misuse like
`OrderFactory::for(BarFactory::new(), 'ShippingCity')` — that would have
silently mis-wired a Bar entity into the City slot in v1.

## `Sequence` context object

`sequence()` callables now receive a single `Sequence` value object instead
of two positional args. Exposes the current row's full context:

``` php
ArticleFactory::new()
    ->count(5)
    ->sequence(fn (Sequence $s) => [
        'rank' => $s->index,            // 0-based
        'position' => $s->position,     // 1-based
        'total' => $s->total,
        'is_first' => $s->isFirst,
        'is_last' => $s->isLast,
        'slug' => $s->generator->slug(),
        'parent' => $s->factory->getTable()->getAlias(),
    ])
    ->saveMany();
```

The Sequence object is constructed by the data compiler and self-validates;
callables never need to instantiate it. The new `sequenceField()` helper
covers the per-field-cycle case without writing a callable:

``` php
ProductFactory::new()
    ->count(6)
    ->sequenceField('status', 'draft', 'review', 'published')
    ->saveMany();
```

See the [Sequence section](factories.md#sequence-per-row-state) of the
factories guide.

## `Story` scenarios

A Foundry-style abstract that adds **named entity pools** on top of the
existing `FixtureScenarioInterface`. Seed data once, store named handles,
sample from them in the test body — no rebuilding, no explicit threading:

``` php
class BlogStory extends Story
{
    protected function build(): void
    {
        $this->addToPool('authors', UserFactory::new()->count(10)->saveMany());
        $this->addToPool('categories', CategoryFactory::new()->count(3)->saveMany());

        ArticleFactory::new()->count(50)->saveMany();
    }
}

// In the test:
$story = $this->loadFixtureScenario(BlogStory::class);
$author = $story->getRandom('authors');
$twoCategories = $story->getRandomSet('categories', 2);
```

Backwards-compatible: existing scenarios that implement
`FixtureScenarioInterface` directly keep working unchanged. See the
[Scenarios guide](scenarios.md).

## `TableAssertionsTrait`

Expressive database-state assertions composed over `Factory::query()`. The
trait is opt-in (`use TableAssertionsTrait;`) — no static read surface is
added to `BaseFactory`.

``` php
public function testCreate(): void
{
    $this->post('/articles', ['title' => 'Hello']);

    $this->assertTableHas(ArticleFactory::class, ['title' => 'Hello']);
    $this->assertTableCount(ArticleFactory::class, 1);
    $this->assertTableMissing(ArticleFactory::class, ['status' => 'spam']);
    $this->assertEntityExists($article);
    $this->assertEntityMissing($deletedArticle); // refuses never-persisted entities
}
```

Sharp failure messages spell out what was expected and what was found,
including the actual value of relevant columns. See the
[Queries guide](queries.md#expressive-database-assertions-tableassertionstrait).

## Eager transaction strategy restored

`FactoryTransactionStrategy` is eager by default again. `setupTest()` opens a
transaction on the primary test connection *up-front* so that direct
`$table->save($entity)` / raw inserts inside a test are also rolled back at
teardown — not just operations that go through a Factory's `save()`.

v1.3 had flipped this to lazy, which silently broke tests that mixed Factory
build with direct `$table->save()` (the testFind / testValidationDefault
pattern) — the direct save ran outside any transaction and leaked across
methods. v2 restores the eager default while keeping additional connections
lazy.

If your suite is entirely Factory-based and you want the lazy contract back,
opt in via `LazyFactoryTransactionStrategy` (whole suite) or
`LazyTransactionTrait` (per test class).

## `strictDefinition` FK-in-`definition()` detector

Catches the silent footgun where a factory's `definition()` returns a
belongsTo FK column directly (e.g. `'author_id' => $generator->randomDigit()`).
That pattern silently overrides whatever parent a caller later composes via
`->with('Author', ...)` — the dangling-id bug that's hard to debug because
the test reads green.

Enabled by default in v2; the detector emits a deprecation that names the
column, the association, and the migration path:

```
ArticleFactory::definition() returns "author_id", which is the foreign-key
column for the "Author" belongsTo association. Move association composition
out of definition() — use ->with('Author') in configure(), a forAuthor() /
withAuthor() helper, or pass the association at the call site.
```

Opt out transitionally:
`Configure::write('FixtureFactories.strictDefinition', false);` — removed in
the next major.

See the
[FK-in-definition guide](foreign-keys-in-definition.md) for the migration
playbook.

## Bake quality

- **`TimestampBehavior`-managed columns are no longer baked.** Without this,
  a factory baked for a `'created'/'modified'` table emitted
  `$generator->datetime()` for both, then TimestampBehavior deferred to the
  already-set values and the fixture row shipped with random timestamps
  instead of the test-run's `now`. Detection is by class (so aliased
  `'className' => 'Timestamp'` loads are caught too) and only Model.beforeSave
  writers with `new` or `always` qualify.
- The default-data guesser was extracted into a dedicated `DefaultDataGuesser`
  class that subclasses / projects can override or extend.

See [Bake reference](../reference/bake.md).

## Notable bug fixes worth knowing

- `recycle()` now substitutes mid-chain required parents (the
  `withRequiredParents()` interaction).
- `foreignKey => false` belongsTo composition works at persist time
  (was crashing with "Cannot set an empty field" in v1).
- `FactoryTransactionStrategy` rollback cascade and cross-connection finalize
  are no longer susceptible to per-connection failures.
- The pinned-FK contract is now uniform across all four entry-paths:
  array-instantiation, entity-instantiation, the additive
  `requiredParentAssociations()` hook, and `configure()`-time. Sequence
  overriding an instantiation pin is correctly detected.

## Removed in v2

For the mechanical surface — old method names, deprecated wrappers, removed
helpers — see [upgrading](upgrading.md). The bundled Rector config covers the
safe renames automatically.

## Where to go next

- New to the plugin? [Getting Started](index.md).
- Upgrading from 1.4? [Upgrading guide](upgrading.md).
- Want the full feature reference? Each major surface has its own page —
  start with [Associations](associations.md), [Required parents](required-parents.md),
  and [Queries](queries.md).
