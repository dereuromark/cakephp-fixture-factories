---
title: Foreign keys in definition()
description: Why FK columns belong to ->with() composition, never to the scalar default template
---

# Foreign keys in `definition()`

`definition()` is for the entity's own scalar columns — `title`, `street`, `price`, the entity's own `uuid`. Foreign-key columns belong somewhere else: in the `->with('Alias')`, `->for()`, or factory-helper layer that composes the related parent.

Returning a FK column from `definition()` is a smell. The plugin detects it at runtime and emits an `E_USER_DEPRECATED` pointing at the offending column. The check is on by default; opt out per the [configuration reference](/reference/configuration#strictdefinition) while you migrate.

## Why it matters

Consider this factory:

```php
class ArticleFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Articles';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'title' => $generator->sentence(),
            'author_id' => $generator->numberBetween(1, 100), // smell
        ];
    }
}
```

Two failure modes hide here:

**1. Dangling FK when no parent is composed.** `ArticleFactory::new()->save()` stores `author_id = 47`. Row 47 in `authors` may or may not exist. The test passes; the next test joining `Articles` → `Authors` finds no match, mid-flight, and the cause looks like anything but a bad default.

**2. Silently overwritten when a parent IS composed.** `ArticleFactory::new()->with('Author', $author)->save()` persists `$author`, gets its real id back, and writes that to `author_id`. The `numberBetween()` line is now dead code — but it lies about intent. A reader sees `author_id` in `definition()` and assumes it does something. Removing it would change no behavior, but you have to prove that line-by-line.

Both shapes hide the real source of the FK. The detector flags the line whether `->with()` is present or not.

## The fix

Drop the FK column from `definition()`. Compose the parent in `configure()` for the always-on case, or expose a helper for the sometimes-on case.

```php
class ArticleFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Articles';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'title' => $generator->sentence(),
        ];
    }

    protected function configure(): static
    {
        return $this->forAuthor();
    }

    public function forAuthor(mixed $parameter = null): static
    {
        return $this->for(AuthorFactory::new($parameter));
    }
}
```

Now `ArticleFactory::new()->save()` builds and persists a real `Author`, and `author_id` points at that author's id. `ArticleFactory::new()->forAuthor($existingAuthor)` reuses one you already have. No dangling ids, no dead defaults.

## When you genuinely need a literal id

Sometimes the test wants a specific orphan id — to assert how the code handles a parent that disappeared, for instance. Don't put that in `definition()`. Make it explicit at the call site, or behind a named helper:

```php
public function withOrphanAuthorId(): static
{
    return $this->patchData(['author_id' => 999999]);
}
```

The unusual case is now named, traceable, and visible. Tests that don't call `withOrphanAuthorId()` get a real composed parent, not a silent default that points at nothing.

## What the detector flags

The detector inspects the `belongsTo` associations declared on the factory's root table and builds a map of `foreign_key_column → association_alias`. Any column returned from `definition()` that matches a FK column triggers the deprecation — once per `(factory class, column)` per process, so a smelly factory called 10,000 times produces exactly one message.

Non-FK `*_id` columns (external system ids, enum-like status codes, anything *not* declared as a belongsTo FK) are not flagged. The detector consults the schema, not column-name patterns.

## Migration cookbook

For each factory the detector flags:

1. Identify the FK column it named and the matching association alias.
2. Remove the column from `definition()`.
3. Add a `forFoo()` / `withFoo()` helper if one doesn't exist.
4. Decide whether the association should be auto-composed in `configure()` (always-on parent) or stay opt-in at the call site (sometimes-on parent).
5. Update tests that previously asserted on the random FK value — they should now assert against the composed parent's real id.

## Migrating off FK-in-`definition()`

::: warning This is a call-site sweep, not just a factory edit
Removing the FK from `definition()` and composing the parent via
`configure()->with('Alias')` is only half the job. **Every existing test
that pins that FK explicitly** — `Factory::new(['author_id' => $x])`,
`->setField('author_id', $x)`, `->patchData(['author_id' => $x])` — will
**silently break** the moment the factory starts composing the parent,
because the composed parent's freshly-persisted id **overwrites** the
explicitly-set FK. Nothing errors; the row just points at the wrong parent.
Budget for a suite-wide grep, not a one-line factory change.
:::

### Preferred idiom: `->with('Alias', $persistedEntity)`

When a test needs the row to belong to a *specific* parent, pass that parent
through the composition layer instead of pinning the scalar FK:

```php
// Before — pins the scalar FK, breaks once the factory composes Author
$article = ArticleFactory::new(['author_id' => $author->id])->persist();

// After — the composed parent IS the one you want
$article = ArticleFactory::new()->with('Author', $author)->persist();
```

`->with('Alias', $entity)` accepts a persisted entity, an array of fields, or
a factory. It always wins over an auto-composed `configure()->with()`, so the
parent you hand in is the parent the row ends up with.

### Escape hatch: `->without('Alias')`

Occasionally a test genuinely wants a bare scalar FK — an orphan id, a fixed
legacy id, a value the system-under-test is expected to handle as
"parent missing". In that case keep the explicit FK and drop the composed
parent for that build:

```php
$article = ArticleFactory::new(['author_id' => 999999])
    ->without('Author')
    ->persist();
```

`->without('Alias')` cancels the `configure()`-composed parent for this build
only, so the scalar `author_id` survives unmodified and no `Author` row is
created.

::: tip Auto-skip
As of the [`autoSkipComposeOnExplicitForeignKey`](/reference/configuration#autoskipcomposeonexplicitforeignkey)
flag (default `true`), supplying the FK explicitly at the call site
*automatically* drops the composed parent for that build — the explicit FK
wins without a manual `->without('Alias')`. The escape hatch above is only
needed when that flag is turned off.
:::

### Deep-cascade caveat

`->with('Alias', $entity)` composes the parent into the build graph. If you
take a built (not persisted) entity and save it externally:

```php
$article = ArticleFactory::new()->with('Author', AuthorFactory::new())->getEntity();
$data = $article->toArray();
$table->save($table->newEntity($data)); // cascade-saves the unsaved Author too
```

the external `$table->save()` will try to **cascade-save the composed but
unpersisted parent**, which may collide with uniqueness rules or insert rows
you did not expect. Either `->persist()` through the factory (it handles the
save order), or compose with an already-persisted entity so there is nothing
left to cascade.

## Opt-out while migrating

If a downstream suite has many offenders and can't migrate in one pass:

```php
// tests/bootstrap.php
Configure::write('FixtureFactories.strictDefinition', false);
```

The opt-out silences the detector entirely. It is transitional — the next major release removes the flag and promotes the deprecation to an exception. Plan the migration before then; the detector's report tells you exactly which factories and columns to touch.
