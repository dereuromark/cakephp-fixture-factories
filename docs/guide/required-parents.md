---
title: Required parents
description: One call composes every NOT NULL belongsTo parent — the ergonomic counterpart to the FK-in-definition() detector
---

# Required parents

`withRequiredParents()` composes every belongsTo parent the root table
*requires* — recursively down the chain — so a row built by the factory
satisfies its NOT NULL foreign-key constraints with no hand-written
`->with('Alias')` boilerplate.

```php
// Authors.address_id is NOT NULL (belongsTo Address);
// Addresses.city_id is NOT NULL (belongsTo City);
// Cities.country_id is NOT NULL (belongsTo Country).
$author = AuthorFactory::new()->withRequiredParents()->save();
// $author->address_id points at a real Address → real City → real Country.
```

## Why this exists

This is the ergonomic counterpart to the
[FK-in-`definition()` detector](/guide/foreign-keys-in-definition)
(`FixtureFactories.strictDefinition`). That detector pushes foreign-key
population *out* of `definition()` and into association composition — correct,
but without a counterpart it leaves a cliff: every factory whose root table
has NOT NULL belongsTo FKs then needs explicit `->with('Alias')` /
`->for(...)` boilerplate just to persist a single row.

`withRequiredParents()` is that counterpart. The detector says *"don't put the
FK in `definition()`"*; this says *"and here's the one call that builds the
required parents for you."*

## What counts as "required"

Auto-resolved:

- a belongsTo whose foreign key is a **single scalar column** that is
  **`NOT NULL`** in the table schema.

Never auto-resolved:

- a **nullable** FK — the row persists fine without it; silently fabricating
  an optional parent hides intent.
- a **composite-key** belongsTo.
- a belongsTo declared **`'foreignKey' => false`** (custom-condition /
  non-FK join, e.g. the classic uuid join).

The last two are exactly the brittle edge cases that
[PR #85](https://github.com/dereuromark/cakephp-fixture-factories/pull/85) hit:
guessing how to build them is unsafe, so automatic detection refuses. Opt them
in explicitly (see [Override hook](#override-hook)) — never guessed.

## Side-effect free, atomic

`withRequiredParents()` is a composition method like every other `with*()` /
`for*()` call: it only wires parent factories onto the build graph. **Nothing
is written to the database until the root factory is persisted.** The required
parents are created in the same unit as the root entity, so:

- `->build()` stays purely in-memory — no rows are inserted.
- A failed root save does not leave orphaned parent rows behind.
- Chaining order is forgiving: a `->with('Alias', $x)` placed *after*
  `withRequiredParents()` still wins, and no stray parent for that alias is
  written.

## Sharing a parent across a batch

Because the method never persists anything itself, it cannot reuse a
pre-existing row. To make a counted batch — or several factories — share one
parent, build it yourself and hand it to
[`recycle()`](/guide/associations), the established pattern, which composes
cleanly with `withRequiredParents()`:

```php
$country = CountryFactory::new()->save();

AuthorFactory::new()->count(50)
    ->withRequiredParents()
    ->recycle($country) // every author's whole chain reuses this one Country
    ->saveMany();
// 50 authors, 50 addresses, 50 cities — but exactly one country.
```

Without `recycle()`, each produced row gets its own full required chain, which
is the correct default for independent fixtures. Note the cost is the *whole
transitive chain* per row: `->count(50)` on a root three levels deep inserts
50 × every level, not 50 rows. `recycle()` the shared table(s) when that matters
— a recycled entity is substituted at **every depth** of the chain by table
name, for the whole batch. This includes *mid-chain* parents, not just the
leaf: recycling an intermediate required parent reuses it everywhere, because
`withRequiredParents()`' auto-composition is treated as a default (not as
explicit per-branch `with()` intent) and so never blocks recycle substitution.

Two **distinct-alias** belongsTo that happen to target the same table (e.g.
`Address` and `BusinessAddress`, both → `addresses`) each get their own parent
— that is per-alias intent, preserved on purpose. Use `->with('Alias', $entity)`
when you want two aliases to point at the *same* parent.

### Diamond required graphs

When two *different* required parents of the root each require the same
grandparent table — root → `B` and root → `C`, with both `B` → `D` and
`C` → `D` all `NOT NULL` — the two branches are composed independently, so a
single root produces **two `D` rows**. This is consistent with the
independent-fixtures default above, not a bug: nothing is persisted at
composition time, so there is no shared row to reuse. When the diamond should
collapse to one shared grandparent, `recycle()` it — the same table-keyed
substitution applies, so both branches reuse it:

```php
$d = DFactory::new()->save();

RootFactory::new()
    ->withRequiredParents()
    ->recycle($d) // both B's and C's required D resolve to this one row
    ->save();
```

## Shared-primary-key and cyclic graphs

Shared-primary-key 1:1 associations (the FK column *is* the table's primary
key, `child.id` → `parent.id`) are treated as ordinary required parents and
auto-composed.

A cycle of **required** (NOT NULL) belongsTo FKs — a self-referential parent,
or `A -> B -> A` — is mathematically unsatisfiable: no row in the cycle can be
inserted without a parent row that itself needs one. `withRequiredParents()`
detects this and throws a `FixtureFactoryException` with an actionable message
rather than silently producing a factory that dies on a confusing NOT NULL
violation at save time. Break the cycle yourself: pin the cyclic FK at the
call site and exclude the alias via `$except`
(`->withRequiredParents(['CyclicAlias'])`), or exclude it through the
`excludedRequiredParentAssociations()` override hook.

## `$except`: pin one FK literally

Skip a named association when the test pins that FK at the call site for a
column-scope assertion:

```php
$author = AuthorFactory::new(['address_id' => $address->id])
    ->withRequiredParents(['Address'])
    ->save();
// $author->address_id === $address->id; no throw-away Address built.
```

## `maxDepth`: cap the recursion depth

By default `withRequiredParents()` recurses the *whole* NOT NULL chain. Pass
`maxDepth` to compose only the first N levels below the root:

```php
// Only the root's direct required parents — not their parents.
AuthorFactory::new()->withRequiredParents(maxDepth: 1)->build();

// Root's parents and their parents, but not the third level.
AuthorFactory::new()->withRequiredParents(maxDepth: 2)->build();

// Explicit null is the unbounded default (same as no argument).
AuthorFactory::new()->withRequiredParents(maxDepth: null)->build();
```

`$except` and `maxDepth` are independent — pass both as named arguments:
`->withRequiredParents(['BusinessAddress'], maxDepth: 2)`.

`maxDepth` caps the *auto-recursion* this method performs. It does **not**
suppress a composed parent factory's own `configure()` / `for()` defaults —
those are the factory author's deliberate choice and always apply. So a depth
cap is fully effective for the bare factories `withRequiredParents()` targets
(no FKs in `definition()`/`configure()`, the `strictDefinition` model); if a
parent factory self-composes a chain via `configure()`, that chain is still
built regardless of `maxDepth`.

::: warning A cap below the real required depth produces an un-persistable row
This is by design — the exact same contract as `$except`. If `maxDepth`
stops before a deeper `NOT NULL` FK is satisfied, `->build()` still returns an
in-memory entity, but `->save()` fails with a `NOT NULL` violation. Use
`maxDepth` only when you know the deeper levels are nullable, pinned, or
recycled; otherwise omit it and let the full chain compose.

This also applies to the [required-parent cycle fast-fail](#shared-primary-key-and-cyclic-graphs):
a cycle *beyond* the cap is never reached, so it degrades from the actionable
`FixtureFactoryException` to a generic save-time `NOT NULL` error. A cycle
*within* the cap still throws as usual.
:::

### `strict`: turn the silent shortfall into a clear error

Opt in with `strict: true` when you want a capped chain that is *too shallow*
to fail loudly at the call site instead of silently producing an
un-persistable row:

```php
// Throws FixtureFactoryException: maxDepth:1 leaves Address's required
// City unsatisfied.
AuthorFactory::new()->withRequiredParents(maxDepth: 1, strict: true);

// No throw — the cap covers the whole required chain.
AuthorFactory::new()->withRequiredParents(maxDepth: 9, strict: true)->save();
```

`strict` only fires when `maxDepth` actually truncates a *needed* parent: a
boundary parent that still has its own required belongsTo not already composed
(`configure()` / `->with()` / `->for()`), pinned, or excepted. It is a no-op
without `maxDepth` (a full chain is never truncated), and it also restores an
actionable message for a [cycle beyond the cap](#shared-primary-key-and-cyclic-graphs)
instead of the generic save-time error. Default is `strict: false` — the
silent contract above.

`maxDepth` must be a positive integer or `null`. `0` or a negative value
throws an `InvalidArgumentException` at call time — "compose zero required
parents" is just not calling `withRequiredParents()`.

## Composes cleanly with the rest of the layer

- An alias already composed as a **factory** —
  `->with('Alias', SomeFactory::new())`, `->for(...)`, or a `configure()`
  default — is **kept** (your parent is never replaced) and **recursively
  enriched**, so that parent's *own* required grandchildren are satisfied too.
  This means `->with('Address', AddressFactory::new())->withRequiredParents()`
  still persists: the `Address` you supplied gets its required `City` → `Country`.
- An alias composed from a **concrete entity** —
  `->with('Alias', $savedEntity)` — is left completely untouched: you
  specified that exact row.
- A FK pinned at the call site (`Factory::new(['fk' => x])`, `->state()`,
  `->setField()`, `->sequenceField()`, or `->sequence()` when
  every row sets it non-null) is detected: the alias is treated as already
  satisfied, so it composes cleanly with
  [`autoSkipComposeOnExplicitForeignKey`](/reference/configuration#autoskipcomposeonexplicitforeignkey)
  and never double-composes. The pin check applies uniformly to
  auto-detected NOT NULL aliases **and** to aliases opted in via the
  [`requiredParentAssociations()`](#factory-class-hooks-add-and-exclude)
  additive hook. An instantiation pin (`Factory::new(['fk' => x])`) is
  only honored when no `sequence()` / `sequenceField()` touches the same
  field; if it does, the instantiation pin can be overridden at build
  time, so the parent is composed for safety.

## Factory-class hooks: add and exclude

Two symmetric protected hooks let a factory class shape its own required-parent
set, independently of the per-call `$except` argument:

```php
class BillFactory extends BaseFactory
{
    /**
     * Add aliases auto-detection refuses on its own (typically a nullable
     * single-scalar FK the factory wants composed regardless).
     *
     * @return array<int, string>
     */
    protected function requiredParentAssociations(): array
    {
        return ['OptionalAudit']; // nullable FK, but always composed here
    }

    /**
     * Drop auto-detected NOT NULL parents the factory satisfies another way
     * — a DB default, a trigger, a custom join the caller always supplies.
     * The factory-class-level counterpart to per-call `$except`, so call
     * sites stay clean.
     *
     * @return array<int, string>
     */
    protected function excludedRequiredParentAssociations(): array
    {
        return ['LegacyTenant']; // FK populated by a DB default
    }
}
```

Resolution order: `auto-detected ∪ requiredParentAssociations() − excludedRequiredParentAssociations() − $except`.
The class-level exclude wins over the additive hook; the per-call `$except`
argument subtracts from the same resolved set.
Each is independent; either may stay at its default (`[]`).

::: tip `foreignKey => false` and composite-key opt-in are both supported
The additive `requiredParentAssociations()` hook (and `->with('Alias', ...)`)
support **`foreignKey => false`** custom-condition belongsTo: the parent is
built and saved independently of the cascade (Cake's
`BelongsTo::saveAssociated` cannot handle them — the relation is queried by
custom conditions at read time, with no FK column to populate). The parent
row exists in the database after `save()` and is reachable via
`->find()->contain('Alias')`, but is **not** attached to the in-memory root
entity (`$root->{alias}` stays `null` post-save) — attaching it would re-fire
the broken cascade.

**Composite-key** belongsTo can also be opted in through the same additive
hook. Those follow the normal to-one cascade: the parent is composed and Cake
fills every local FK component from the target binding keys during the root
save.
:::

## It's the pragmatic default — not the assertion tool

`withRequiredParents()` is for *"I just need a persistable row and don't care
which parents"*. When a test **asserts** on a specific parent, still attach it
explicitly so the assertion's intent is visible:

```php
$city = CityFactory::new()->save();
$author = AuthorFactory::new()
    ->with('Address', AddressFactory::new()->with('City', $city))
    ->save();
$this->assertSame($city->id, $author->address->city_id); // intent is visible
```

Mixing both is fine: an explicit `->with()` for the asserted branch, plus
`->withRequiredParents()` for everything else the row needs to persist.
