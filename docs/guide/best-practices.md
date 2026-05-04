---
title: Best Practices
description: Patterns and anti-patterns for building maintainable factories
---

# Best Practices

This page collects patterns that hold up well in real test suites — and the anti-patterns that cause pain over time. Most of it is distilled from Kevin Pfeifer's ([@LordSimal](https://github.com/LordSimal)) write-up [*My learnings after using this plugin for 2 years*](https://github.com/vierge-noire/cakephp-fixture-factories/discussions/242).

## DO: Add a `withXyz()` method for every association

When you bake factories with `-m`, helper methods are added based on your associations. Add them yourself for any association the bake misses — your future self (and your colleagues) will thank you.

```php
class StaffMemberFactory extends BaseFactory
{
    // hasMany Projects → plural
    public function withProjects(mixed $parameter = null, int $n = 1): self
    {
        return $this->with('Projects', ProjectFactory::make($parameter, $n));
    }
}

class TicketFactory extends BaseFactory
{
    // belongsTo Project → singular
    public function withProject(mixed $parameter = null, int $n = 1): self
    {
        return $this->with('Projects', ProjectFactory::make($parameter, $n));
    }
}
```

The two methods are functionally identical, but the singular/plural name signals the cardinality at the call site. `->withProject()` reads as "with a project"; `->withProjects()` reads as "with projects" — the test code immediately tells you what shape you're building.

You don't always know which associations you'll want in tests months from now. Add them all preemptively so nobody has to wire up an association mid-test.

## DON'T: chain `withXyz()` calls inside your factory's helper methods

Tempting:

```php
public function withDomains(mixed $parameter = null, int $n = 1): self
{
    return $this->with(
        'FtpDomains',
        FtpDomainFactory::make($parameter, $n)->withFtpLoginData(),
    );
}
```

It looks clever — one short call in the test (`->withDomains()`) builds two layers at once. But the `withFtpLoginData()` is now invisible from the test, and the `FtpLoginData` shape is uncontrollable from the outside. If a test needs custom login data, it has to fight the helper.

Keep helpers single-layer. Build deeper graphs at the call site.

## DON'T: add `withXyz()` calls to `setDefaultTemplate()` — with one exception

Same problem, one level out:

```php
protected function setDefaultTemplate(): void
{
    $this->setDefaultData(function (GeneratorInterface $generator) {
        return [/* ... */];
    })
    ->withFtpLoginData(); // ❌ creates an FtpLoginData on every persist
}
```

Every persisted entity now drags an `FtpLoginData` along, whether the test asked for it or not. You can scrub it with `->without('FtpLoginData')`, but that's paying tax to undo something you set up by mistake.

**The exception: required associations.**
If a column has a `NOT NULL` foreign key (e.g. `addresses.country_id`), the entity simply can't persist without an associated row. In that case, attach the *minimum* the schema demands — and only that:

```php
class AddressFactory extends BaseFactory
{
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(function (GeneratorInterface $generator) {
            return ['street' => $generator->streetAddress()];
        })
        ->withCountry(); // ✅ NOT NULL FK on addresses.country_id
    }
}
```

The same rule applies to required associations inside `withXyz()` helpers — if persisting `FtpDomain` strictly requires an `FtpLoginData`, the helper has to materialize one. The asymmetry is unavoidable; just keep it minimal and document the why.

Rule of thumb: "what does the schema require?" goes in the template. "What does this particular test want?" goes at the call site.

## DON'T: nest `with()` calls in test cases

```php
$entity = ProjectFactory::make([
    'project_end' => null,
    'is_duedate_notification_sent' => 0,
    'duedate' => Carbon::now()->subDays(5),
])
    ->with(
        'StaffMembersProjects',
        StaffMembersProjectFactory::make()
            ->without('Projects')
            ->with('ProjectRoles', ['id' => 1]),
    )
    ->persistOne();
```

This is hard to read, hard to refactor, and the `->without('Projects')` is a smell that the join model's defaults don't match what the test wants.

## DO: build sub-entities first, then attach

Build the join row exactly the way you want it, then pass it to the parent:

::: code-group

```php [One association with custom data]
$staffMembersProject = StaffMembersProjectFactory::make()
    ->withStaffMember()
    ->withProjectRole(['id' => 1])
    ->getEntity();

$project = ProjectFactory::make([
    'project_end' => null,
    'is_duedate_notification_sent' => 0,
    'duedate' => Carbon::now()->subDays(5),
])
    ->withStaffMembersProjects($staffMembersProject)
    ->persistOne();
```

```php [Two associations, one shared entity]
$customer = EasybillCustomerFactory::make()->persistOne();

$charge = EasybillChargeFactory::make()
    ->withEasybillCustomer($customer)
    ->persistOne();

$project = ProjectFactory::make()
    ->withEasybillCustomer($customer)
    ->withEasybillCharges($charge)
    ->persistOne();
```

```php [Multiple generated children]
$staffMembersProjects = StaffMembersProjectFactory::make(3)
    ->withStaffMember()
    ->withProjectRole()
    ->getEntities();

$project = ProjectFactory::make()
    ->withEasybillCustomer()
    ->withStaffMembersProjects($staffMembersProjects)
    ->persistOne();
```

```php [Three levels with a shared entity]
$customer = EasybillCustomerFactory::make()->persistOne();

$easybillDocument = EasybillDocumentFactory::make()
    ->withEasybillCustomer($customer)
    ->withEasybillDocumentType()
    ->persistOne();

$projectsEasybillDocument = ProjectsEasybillDocumentFactory::make()
    ->withProjectDocumentType()
    ->withEasybillDocument($easybillDocument)
    ->getEntity();

$project = ProjectFactory::make()
    ->withEasybillCustomer($customer)
    ->withProjectsEasybillDocuments($projectsEasybillDocument)
    ->persistOne();
```

:::

The shape of every sub-entity is right there in the test. No nested `with()`, no `->without()` workarounds, no surprises.

## Avoid `->without()` when you can

If you've followed the rules above, `->without()` becomes unnecessary almost everywhere. Each sub-entity is built explicitly and attached only where it's wanted, so there's nothing to subtract. When you do reach for `->without()`, treat it as a sign that some helper or default is doing too much, and consider trimming it.

## Know when to use `getEntity()` vs `persistOne()` / `persistMany()`

Both walk the same association graph. The difference is whether they touch the database:

- **`getEntity()` / `getEntities()`** — build entities in memory only. Use these when the test doesn't need DB rows: unit-testing a service that takes an entity, or generating fixtures for a select-query mock.
- **`persistOne()`** — save a single configured entity and return it (typed). Use it whenever the factory was created with `make()` or `make([oneRow])`.
- **`persistMany()`** — save all configured entities and return them as a typed array. Works for any factory shape; pick it when the factory produces multiple entities, or when callers iterate / assert on counts.

`persist()` is still available for backwards compatibility but is deprecated — it returns either an entity or an iterable depending on the factory shape, which is hard for static analysis. Prefer `persistOne()` / `persistMany()`.

```php
// Unit test: no DB needed
$article = ArticleFactory::make()->withAuthors(2)->getEntity();
$result = $this->ArticlesService->summarize($article);
$this->assertSame('…', $result);

// Integration test: needs DB, single entity
$article = ArticleFactory::make()->withAuthors(2)->persistOne();
$this->get(['controller' => 'Articles', 'action' => 'view', $article->id]);

// Integration test: needs DB, many entities
ArticleFactory::make(5)->withAuthors(2)->persistMany();
$this->get(['controller' => 'Articles', 'action' => 'index']);
$this->assertResponseContains('5 articles');
```

If you're not sure which you need, default to `getEntity()` — fewer DB writes mean faster, cleaner tests.

## Build a fresh factory per test

Factory state is mutable. A factory configured in `setUp()` and reused across multiple `test*` methods can leak state — especially when you've used `->unique()`, `setGenerator()`, or `->without()`. Build the factory inside the test that needs it, not as a shared instance variable.

```php
// Avoid
protected function setUp(): void
{
    $this->articleFactory = ArticleFactory::make()->withAuthors(2);
}

// Prefer
public function testIndex(): void
{
    $article = ArticleFactory::make()->withAuthors(2)->persistOne();
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

- One `withXyz()` per association — name signals cardinality.
- Helpers stay single-layer; don't chain associations inside them, except where the schema demands it.
- `setDefaultTemplate()` sets fields and any *required* associations — never optional ones.
- In tests, build the graph bottom-up: leaf entities first, parents last.
- `->without()` is a smell; aim to make it unnecessary.
- Pick `getEntity()` vs `persistOne()` / `persistMany()` deliberately; default to in-memory.
- Build a fresh factory per test — never share instances.
- `->unique()` is for high-cardinality fields; promote shared setups to scenarios.

Following these keeps factories small, predictable, and quick to extend — even years into a project.
