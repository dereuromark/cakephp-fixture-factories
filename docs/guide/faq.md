---
title: FAQ
description: Frequently asked questions
---

# FAQ

## Why use factories instead of `$fixtures` arrays?

Factories are programmatic — you describe data in code, with helper methods, associations, and scenarios. Compared to static `$fixtures` arrays:

- No test pulls in 30 unrelated rows just to satisfy FK constraints — each test builds exactly what it needs.
- Associations are first-class: `->with('Authors[3].Address.City.Country')` is one line; the equivalent fixture file is dozens.
- Defaults live in one place per model. Override per test with `make(['title' => 'Foo'])`.

See [Why factories?](why-factories) for a full side-by-side comparison.

## Can I mix factories and `$fixtures`?

Technically yes — useful when you're migrating a project incrementally. **It is not recommended as a long-term setup.**

The `FactoryTransactionStrategy` only tracks tables written via `factory->persist()`. When you mix in classic `$fixtures`, the two state machines can drift apart easily — the strategy may not know about rows seeded from a fixture file, and a fixture file may not be aware of rows a factory left behind. Symptoms: unexplained FK collisions, ordering-sensitive tests, leaks between cases.

If you have to mix during a migration, keep classic fixtures only for stable reference data (e.g. countries, roles), and move everything tied to test logic into factories or [scenarios](scenarios). Aim to retire the remaining fixtures as soon as you can.

## Does this work outside CakePHP?

Yes. Factories work standalone via the `getTable()` API. See [Associations for non-CakePHP apps](non-cakephp-associations) for the wiring.

## Faker or DummyGenerator — which should I pick?

- **Faker** if you need extensive locale-specific data, or are on PHP < 8.3.
- **DummyGenerator** if you're on PHP 8.3+ and want a leaner footprint with native enum support.

Both are interchangeable per-factory or globally. See [Generators](generators) for the full matrix.

## How do I share complex setups across tests?

Wrap them in a [scenario](scenarios). A scenario is a class with a `load()` method that persists a coherent set of fixtures (e.g. "an in-progress checkout" or "10 Australian authors"). Tests then call `$this->loadFixtureScenario(...)` to materialize the scenario.

## What does `FactoryTransactionStrategy` actually do?

It wraps every test in a transaction and rolls back on tear-down — so factory data, controller saves, and any other DB writes all disappear. No manual `$fixtures` listing, no truncation, and unique generator state is reset between tests. See [Setup](setup#factory-transaction-strategy-recommended).

## Will tests be slower with factories?

Generally no — often faster. Transaction rollback is cheaper than truncation, and tests no longer pull in unused fixture rows. The generator overhead per row is microseconds.

## Can I bake factories for a plugin?

Yes:

```bash
bin/cake bake fixture_factory MyModel -p MyPlugin
```

Factories land in the plugin's `tests/Factory/` directory. See [Bake](/reference/bake).

## Can I use a custom generator?

Yes. Implement `CakephpFixtureFactories\Generator\GeneratorInterface` and register it:

```php
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;

CakeGeneratorFactory::registerAdapter('custom', MyCustomAdapter::class);
Configure::write('FixtureFactories.generatorType', 'custom');
```

See [Generators — custom generators](generators#custom-generators).

## How do I migrate from `vierge-noire/cakephp-fixture-factories`?

The main change is `Faker\Generator` type-hints become `GeneratorInterface`. See the [migration guide](migration).
