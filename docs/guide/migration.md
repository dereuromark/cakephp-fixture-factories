---
title: Migration from vierge-noire
description: Upgrade guide for users coming from vierge-noire/cakephp-fixture-factories
---

# Migrating from vierge-noire/cakephp-fixture-factories

This package is a maintained fork of [vierge-noire/cakephp-fixture-factories](https://github.com/vierge-noire/cakephp-fixture-factories). If you're upgrading from `vierge-noire/cakephp-fixture-factories:^3.0`, the main breaking change is the **generator type**.

Callbacks in `setDefaultTemplate()` now receive `GeneratorInterface` instead of `Faker\Generator`:

```diff
- use Faker\Generator;
+ use CakephpFixtureFactories\Generator\GeneratorInterface;

  protected function setDefaultTemplate(): void
  {
-     $this->setDefaultData(function (Generator $faker) {
+     $this->setDefaultData(function (GeneratorInterface $generator) {
          return [
-             'email' => $faker->email,
+             'email' => $generator->email(),
          ];
      });
  }
```

## Key changes

- Replace `Faker\Generator` type hints with `GeneratorInterface`.
- Use `$this->getGenerator()` instead of the deprecated `$this->getFaker()`.
- Prefer method calls `->email()` over property access `->email` (both still work for Faker, but only methods work for DummyGenerator).

## Why?

The fork introduces a generator abstraction so you can pick between [Faker](https://github.com/fakerphp/faker) and [DummyGenerator](https://github.com/johnykvsky/dummygenerator) — or plug in a custom adapter. Faker remains the default, so existing factories keep working with minimal type-hint updates.

See [Generators](generators) for a full comparison and the per-method compatibility matrix.

## Other differences

- Modern, configurable type guessing per field name/type when baking — see [Bake command](/reference/bake).
- Drops support for older CakePHP versions; check `composer.json` for the supported range.
- PHPStan level 8 across the codebase — better IDE / static-analysis support for your factories.
