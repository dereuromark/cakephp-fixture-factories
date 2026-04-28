---
title: Why factories?
description: Factories vs. classic CakePHP fixtures
---

# Why factories?

CakePHP ships with `$fixtures` arrays — static rows defined per test class. Fixture factories replace that pattern with code: per-model factory classes that build entities on demand, with sensible defaults and association helpers.

## Side-by-side

A test that needs an article with 3 authors, each with an address in a known country.

::: code-group

```php [Classic $fixtures]
class ArticlesIndexTest extends TestCase
{
    protected array $fixtures = [
        'app.Articles',
        'app.Authors',
        'app.ArticlesAuthors',
        'app.Addresses',
        'app.Cities',
        'app.Countries',
    ];

    public function testIndex(): void
    {
        // The fixture files must contain matching rows for every association.
        // If they don't, test setup fails with FK errors and you go fix the
        // YAML/array files until they do.

        $articles = $this->fetchTable('Articles')
            ->find()
            ->contain(['Authors.Address.City.Country'])
            ->where(['Authors.Address.City.Country.name' => 'Kenya'])
            ->all();

        $this->assertCount(1, $articles);
    }
}
```

```php [Fixture factories]
class ArticlesIndexTest extends AppTestCase
{
    public function testIndex(): void
    {
        ArticleFactory::make()
            ->with('Authors[3].Address.City.Country', ['name' => 'Kenya'])
            ->persist();

        $articles = $this->fetchTable('Articles')
            ->find()
            ->contain(['Authors.Address.City.Country'])
            ->where(['Authors.Address.City.Country.name' => 'Kenya'])
            ->all();

        $this->assertCount(1, $articles);
    }
}
```

:::

The factory version:
- doesn't list fixtures (the [Factory Transaction Strategy](setup#factory-transaction-strategy-recommended) rolls back automatically);
- builds the exact graph the test asserts against — no unrelated rows;
- expresses intent in one line.

## What you give up

You lose static fixture files. That's mostly upside, but a few cases still favor the classic approach:

- **Imported reference data** that's identical across all tests (e.g. a fixed list of countries from a CSV). You can still load these as a [scenario](scenarios) at suite setup, but a one-off classic fixture is sometimes simpler.
- **Cross-language ecosystems** where another tool ingests the same fixture YAML. Stays the same.

In practice, the two coexist — many teams keep a small "seed" fixture for reference data and use factories for everything else.

## What you gain

| | Classic `$fixtures` | Fixture factories |
|---|---|---|
| Defaults | Repeated in every fixture row | Once, in `setDefaultTemplate()` |
| Associations | Manually link FKs, list every fixture | `->with('Authors[3].Address.City.Country')` |
| Per-test data | Edit YAML/array — touches all tests | Override on the factory call |
| Required-field churn | Add column → update every fixture | Add to template; existing tests keep working |
| Reuse across tests | Copy fixtures or load all | [Scenarios](scenarios) |
| Listing fixtures | Mandatory `$fixtures = [...]` arrays | Not needed with the recommended strategy |
| Generator data | Static strings | Faker/DummyGenerator with locales, uniqueness, enums |

## Where to start

1. Install: `composer require --dev dereuromark/cakephp-fixture-factories`
2. Switch to [FactoryTransactionStrategy](setup#factory-transaction-strategy-recommended).
3. [Bake](/reference/bake) factories for one model. Replace fixture loading in one test class. Keep the rest unchanged.
4. Migrate the rest incrementally as you touch tests.

There's no flag day. Both styles run side by side until you've moved everything.
