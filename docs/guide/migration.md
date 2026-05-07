---
title: Migration from vierge-noire
description: Upgrade guide for users coming from vierge-noire/cakephp-fixture-factories
---

# Migrating from vierge-noire/cakephp-fixture-factories

This package is a maintained fork of [vierge-noire/cakephp-fixture-factories](https://github.com/vierge-noire/cakephp-fixture-factories). If you're upgrading from `vierge-noire/cakephp-fixture-factories:^3.0`, the breaking changes are:

1. **Default-data API**: `setDefaultTemplate()` + `$this->setDefaultData(...)` is replaced by a single `definition(GeneratorInterface $generator): array` method.
2. **Generator type**: callbacks receive `GeneratorInterface` instead of `Faker\Generator`.

If you don't migrate the default-data API, your factories will silently produce empty data on v2 — `setDefaultTemplate()` is no longer wired up.

## Default-data API

Replace `setDefaultTemplate()` + `setDefaultData()` with `definition()`:

```diff
- use Faker\Generator;
+ use CakephpFixtureFactories\Generator\GeneratorInterface;

- protected function setDefaultTemplate(): void
- {
-     $this->setDefaultData(function (Generator $faker) {
-         return [
-             'email' => $faker->email,
-         ];
-     });
- }
+ public function definition(GeneratorInterface $generator): array
+ {
+     return [
+         'email' => $generator->email(),
+     ];
+ }
```

The bundled Rector ruleset performs this rewrite automatically:

```bash
vendor/bin/rector process tests --config rector.php
```

See the [v1 → v2 upgrade guide](upgrading.md) for the full ruleset and the call-site renames it covers.

## Key changes

- Migrate `setDefaultTemplate()` wrappers to `definition(GeneratorInterface $generator): array`.
- Replace `Faker\Generator` type hints with `GeneratorInterface`.
- Inside `definition()`, use the `$generator` parameter; `$this->getGenerator()` works too but the parameter is preferred.
- Prefer method calls `->email()` over property access `->email` (both still work for Faker, but only methods work for DummyGenerator).

## Why?

The fork introduces a generator abstraction so you can pick between [Faker](https://github.com/fakerphp/faker) and [DummyGenerator](https://github.com/johnykvsky/dummygenerator) — or plug in a custom adapter. Faker remains the default, so existing factories keep working once the API rename and type-hint updates are in.

See [Generators](generators) for a full comparison and the per-method compatibility matrix.

## Other differences

- Modern, configurable type guessing per field name/type when baking — see [Bake command](/reference/bake).
- Drops support for older CakePHP versions; check `composer.json` for the supported range.
- PHPStan level 8 across the codebase — better IDE / static-analysis support for your factories.
