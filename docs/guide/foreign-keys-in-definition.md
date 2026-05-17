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

For `belongsTo` associations declared with `foreignKey => false` and custom
join conditions, the detector also protects the source-side join column when it
can determine it. On CakePHP `5.4+` this uses the ORM's association
introspection API directly; on CakePHP `5.0`-`5.3` it falls back to a
conservative parser for the common alias-qualified equality patterns.

## Migration cookbook

For each factory the detector flags:

1. Identify the FK column it named and the matching association alias.
2. Remove the column from `definition()`.
3. Add a `forFoo()` / `withFoo()` helper if one doesn't exist.
4. Decide whether the association should be auto-composed in `configure()` (always-on parent) or stay opt-in at the call site (sometimes-on parent).
5. Update tests that previously asserted on the random FK value — they should now assert against the composed parent's real id.

## Pinning the FK at the call site Just Works

The detector steers FK population out of `definition()` and into association
composition. The natural follow-up question is: *if the parent is now composed
in `configure()`, how do I still test "this row belongs to that specific
parent"?* Historically you had to add a manual `->without('Alias')` next to
every `Factory::new(['foo_id' => $x])`, because the composed parent's id would
otherwise overwrite the explicit FK.

That manual `->without()` is no longer needed. With
[`autoSkipComposeOnExplicitForeignKey`](/reference/configuration#autoskipcomposeonexplicitforeignkey)
(default `true`), explicitly providing the FK at the call site automatically
suppresses the `configure()`-composed parent for that build, so the explicit
value wins:

```php
// Factory composes the parent in configure()->with('Author').
// Pinning author_id at the call site auto-skips that composition:
$article = ArticleFactory::new(['author_id' => $author->id])->persist();
// $article->author_id === $author->id  (no throw-away Author row)
```

An explicit `->with('Author', ...)` still always composes — that is an
unambiguous request for a parent and wins over the auto-skip. Turn the flag
off only if a suite relied on the old override behavior.

## Opt-out while migrating

If a downstream suite has many offenders and can't migrate in one pass:

```php
// tests/bootstrap.php
Configure::write('FixtureFactories.strictDefinition', false);
```

The opt-out silences the detector entirely. It is transitional — the next major release removes the flag and promotes the deprecation to an exception. Plan the migration before then; the detector's report tells you exactly which factories and columns to touch.
