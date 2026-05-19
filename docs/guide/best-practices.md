---
title: Best Practices
description: Patterns and anti-patterns for building maintainable factories
---

# Best Practices

This page collects patterns that hold up well in real test suites — and the anti-patterns that cause pain over time. Most of it is distilled from Kevin Pfeifer's ([@LordSimal](https://github.com/LordSimal)) write-up [*My learnings after using this plugin for 2 years*](https://github.com/vierge-noire/cakephp-fixture-factories/discussions/242).

## DO: Add directional helper methods for every association

When you bake factories with `-m`, helper methods are added based on your associations. Add them yourself for any association the bake misses — your future self (and your colleagues) will thank you.

```php
class StaffMemberFactory extends BaseFactory
{
    // hasMany Projects → plural
    public function hasProjects(int $n = 1, mixed $parameter = null): static
    {
        return $this->has(ProjectFactory::new($parameter)->count($n));
    }
}

class TicketFactory extends BaseFactory
{
    // belongsTo Project → singular
    public function forProject(mixed $parameter = null): static
    {
        return $this->for(ProjectFactory::new($parameter));
    }
}
```

The helper names now signal the cardinality at the call site. `->forProject()` reads as "belongs to this project"; `->hasProjects()` reads as "has projects" — the test code immediately tells you what shape you're building.

You don't always know which associations you'll want in tests months from now. Add them all preemptively so nobody has to wire up an association mid-test.

## DO: encode reusable business states as named methods

Prefer this:

```php
$article = ArticleFactory::new()
    ->published()
    ->featured()
    ->save();
```

over scattering raw state arrays through unrelated tests:

```php
$article = ArticleFactory::new()
    ->state([
        'is_published' => true,
        'published' => new FrozenTime('-1 day'),
        'is_featured' => true,
    ])
    ->save();
```

Named methods make test intent obvious, centralize the state shape in one place, and make later refactors much cheaper. Keep `state()` and `setField()` for local one-offs; reach for `published()`, `draft()`, `archived()`, and similar methods when the meaning belongs to the domain.

## DON'T: chain helper methods inside your helper methods

Tempting:

```php
public function hasDomains(int $n = 1, mixed $parameter = null): static
{
    return $this->has(
        FtpDomainFactory::new($parameter)->count($n)->forFtpLoginData(),
    );
}
```

It looks clever, but `forFtpLoginData()` is now invisible from the test, and the `FtpLoginData` shape is uncontrollable from the outside. If a test needs custom login data, it has to fight the helper.

Keep helpers single-layer. Build deeper graphs at the call site.

## DON'T: add optional associations to `definition()` — with one exception

Same problem, one level out:

```php
public function definition(GeneratorInterface $generator): array
{
    return [/* ... */];
}
```

Then add the optional association only at the call site:

```php
FtpDomainFactory::new()->forFtpLoginData()->save();
```

**The exception: required associations.**
If a column has a `NOT NULL` foreign key (e.g. `addresses.country_id`), the entity simply can't persist without an associated row. In that case, attach the *minimum* the schema demands — and only that:

```php
class AddressFactory extends BaseFactory
{
    public function definition(GeneratorInterface $generator): array
    {
        return ['street' => $generator->streetAddress()];
    }

    protected function configure(): static
    {
        return $this->forCountry();
    }
}
```

The same rule applies to required associations inside helper methods — if persisting `FtpDomain` strictly requires an `FtpLoginData`, the helper has to materialize one. The asymmetry is unavoidable; just keep it minimal and document the why.

Rule of thumb: "what does the schema require?" goes in the template. "What does this particular test want?" goes at the call site.

## DON'T: put broad default graphs in `configure()`

This is convenient:

```php
protected function configure(): static
{
    return $this
        ->with('Accounts')
        ->with('Homes')
        ->with('Units')
        ->with('Rooms')
        ->with('Users');
}
```

But on a frequently-used factory it quietly adds database work to every
caller, including tests that do not care about those parents. It also creates
hidden tension with call sites that pin a FK explicitly — the package will do
the right thing and auto-skip the default compose, but that is still a smell
that the factory default is broader than the test wants.

Prefer this:

- keep hot factories light by default
- compose only the parent(s) the current test actually needs
- add explicit helpers for common opt-in shapes

```php
$home = HomeFactory::new()->save();

$activity = ActivityFactory::new()
    ->with('Homes', $home)
    ->save();
```

Use default `configure()` associations only when they are both:

- truly universal for that factory
- cheap enough that every caller should pay for them

If you want visibility into where a default `configure()` association is being
auto-skipped by explicit FK state, enable
`FixtureFactories.warnOnAutoSkippedConfigureAssociation`.

## DON'T: nest `with()` calls in test cases

```php
$entity = ProjectFactory::new([
    'project_end' => null,
    'is_duedate_notification_sent' => 0,
    'duedate' => Carbon::now()->subDays(5),
])
    ->with(
        'StaffMembersProjects',
        StaffMembersProjectFactory::new()
            ->without('Projects')
            ->with('ProjectRoles', ['id' => 1]),
    )
    ->save();
```

This is hard to read, hard to refactor, and the `->without('Projects')` is a smell that the join model's defaults don't match what the test wants.

## DO: build sub-entities first, then attach

Build the join row exactly the way you want it, then pass it to the parent:

::: code-group

```php [One association with custom data]
$staffMembersProject = StaffMembersProjectFactory::new()
    ->forStaffMember()
    ->forProjectRole(['id' => 1])
    ->build();

$project = ProjectFactory::new([
    'project_end' => null,
    'is_duedate_notification_sent' => 0,
    'duedate' => Carbon::now()->subDays(5),
])
    ->hasStaffMembersProjects(1, $staffMembersProject)
    ->save();
```

```php [Two associations, one shared entity]
$customer = EasybillCustomerFactory::new()->save();

$charge = EasybillChargeFactory::new()
    ->forEasybillCustomer($customer)
    ->save();

$project = ProjectFactory::new()
    ->forEasybillCustomer($customer)
    ->hasEasybillCharges(1, $charge)
    ->save();
```

```php [Multiple generated children]
$staffMembersProjects = StaffMembersProjectFactory::new()
    ->count(3)
    ->forStaffMember()
    ->forProjectRole()
    ->buildMany();

$project = ProjectFactory::new()
    ->forEasybillCustomer()
    ->hasStaffMembersProjects(3, $staffMembersProjects)
    ->save();
```

```php [Three levels with a shared entity]
$customer = EasybillCustomerFactory::new()->save();

$easybillDocument = EasybillDocumentFactory::new()
    ->forEasybillCustomer($customer)
    ->forEasybillDocumentType()
    ->save();

$projectsEasybillDocument = ProjectsEasybillDocumentFactory::new()
    ->forProjectDocumentType()
    ->forEasybillDocument($easybillDocument)
    ->build();

$project = ProjectFactory::new()
    ->forEasybillCustomer($customer)
    ->hasProjectsEasybillDocuments(1, $projectsEasybillDocument)
    ->save();
```

:::

The shape of every sub-entity is right there in the test. No nested `with()`, no `->without()` workarounds, no surprises.

## Avoid `->without()` when you can

If you've followed the rules above, `->without()` becomes unnecessary almost everywhere. Each sub-entity is built explicitly and attached only where it's wanted, so there's nothing to subtract. When you do reach for `->without()`, treat it as a sign that some helper or default is doing too much, and consider trimming it.

## Know when to use `build()` vs `save()` / `saveMany()`

Both walk the same association graph. The difference is whether they touch the database:

- **`build()` / `buildMany()`** — build entities in memory only. Use these when the test doesn't need DB rows: unit-testing a service that takes an entity, or generating fixtures for a select-query mock.
- **`save()`** — save a single configured entity and return it (typed).
- **`saveMany()`** — save all configured entities and return them as a typed array. Use it whenever the factory produces multiple entities, or when callers iterate / assert on counts.

```php
// Unit test: no DB needed
$article = ArticleFactory::new()->hasAuthors(2)->build();
$result = $this->ArticlesService->summarize($article);
$this->assertSame('…', $result);

// Integration test: needs DB, single entity
$article = ArticleFactory::new()->hasAuthors(2)->save();
$this->get(['controller' => 'Articles', 'action' => 'view', $article->id]);

// Integration test: needs DB, many entities
ArticleFactory::new()->count(5)->hasAuthors(2)->saveMany();
$this->get(['controller' => 'Articles', 'action' => 'index']);
$this->assertResponseContains('5 articles');
```

If you're not sure which you need, default to `build()` — fewer DB writes mean faster, cleaner tests.

## Build a fresh factory per test

Factories are immutable, so reuse is much safer than before. Even so, building the factory inside the test that needs it usually keeps intent clearer and avoids over-sharing setup.

```php
// Avoid
protected function setUp(): void
{
    $this->articleFactory = ArticleFactory::new()->hasAuthors(2);
}

// Prefer
public function testIndex(): void
{
    $article = ArticleFactory::new()->hasAuthors(2)->save();
    // ...
}
```

## Watch the value space when using `->unique()`

The generator's `->unique()` modifier is great for fields with unique constraints (emails, usernames, slugs) — but it retries until it finds an unseen value, then gives up. Methods with small value spaces (`state()`, `colorName()`, `safeColorName()`) exhaust fast and throw `OverflowException`.

Use `->unique()` on high-cardinality fields. For small pools, generate values yourself or seed an explicit list:

```php
// ✅ Plenty of email addresses to go around
'email' => $generator->unique()->email(),

// ❌ ~50 US states; running 60 makes() will throw
'state' => $generator->unique()->state(),
```

See [Property uniqueness](factories#generator-level-uniqueness-with-unique) for how `->unique()` interacts with the recommended fixture strategy.

## Hoist recurring setups into scenarios

The "build sub-entities first" pattern handles one-off graphs well. When the *same* setup appears across many tests, the next step up is a [scenario](scenarios) — a class that builds a coherent fixture (e.g. "an in-progress checkout") so tests just call `$this->loadFixtureScenario(CheckoutInProgressScenario::class)`. Treat scenarios as the natural home for shared setup, not a dumping ground for every fixture.

## Recap

- One directional helper per association — `forXyz()` for to-one, `hasXyz()` for to-many.
- Helpers stay single-layer; don't chain associations inside them, except where the schema demands it.
- `definition()` sets fields; `configure()` is where required default associations belong.
- In tests, build the graph bottom-up: leaf entities first, parents last.
- `->without()` is a smell; aim to make it unnecessary.
- Pick `build()` vs `save()` / `saveMany()` deliberately; default to in-memory.
- Build a fresh factory per test — never share instances.
- `->unique()` is for high-cardinality fields; promote shared setups to scenarios.

Following these keeps factories small, predictable, and quick to extend — even years into a project.
