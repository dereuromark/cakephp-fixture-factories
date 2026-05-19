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
— a recycled entity is substituted at every depth of the chain by table name,
for the whole batch.

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
`requiredParentAssociations()` override hook.

## `$except`: pin one FK literally

Skip a named association when the test pins that FK at the call site for a
column-scope assertion:

```php
$author = AuthorFactory::new(['address_id' => $address->id])
    ->withRequiredParents(['Address'])
    ->save();
// $author->address_id === $address->id; no throw-away Address built.
```

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
  `->setField()`, `->patchData()`, `->sequenceField()`, or `->sequence()` when
  every row sets it non-null) is detected: the alias is treated as already
  satisfied, so it composes cleanly with
  [`autoSkipComposeOnExplicitForeignKey`](/reference/configuration#autoskipcomposeonexplicitforeignkey)
  and never double-composes.

## Override hook

Return a non-null list from `requiredParentAssociations()` to take explicit
control. The list is **authoritative**: only listed aliases are composed,
automatic detection is bypassed entirely, and the factory author owns
correctness for the listed associations. This is the supported, non-guessing
way to include a composite-key or `foreignKey => false` belongsTo that
automatic detection refuses to build:

```php
class BillFactory extends BaseFactory
{
    /**
     * @return array<int, string>|null
     */
    protected function requiredParentAssociations(): ?array
    {
        // Build these exactly — including a custom-join one automatic
        // detection would never touch.
        return ['Customer', 'Article', 'LegacyUuidParent'];
    }
}
```

Return `null` (the default) to use automatic NOT NULL single-scalar-FK
detection.

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
