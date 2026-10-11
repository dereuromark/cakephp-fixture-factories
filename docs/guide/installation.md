# Installation

This development branch targets CakePHP 6. In your application, enable development dependencies before installing it:

```bash
composer config minimum-stability dev
composer config prefer-stable true
```

Install the plugin and one data generator:

```bash
composer require --dev dereuromark/cakephp-fixture-factories:dev-cake6 fakerphp/faker
```

You can instead install `johnykvsky/dummygenerator`; see [Generators](/guide/generators). The plugin auto-detects the installed generator, preferring Faker when both are present.

## Load the plugin

```bash
bin/cake plugin load --only-debug CakephpFixtureFactories
```

This adds the plugin to `config/plugins.php` with `onlyDebug => true`. Keep test configuration separate from production databases and ensure `debug` is enabled when running development commands. An optional configuration entry also permits production builds without development dependencies:

```php
// Within the existing config/plugins.php array:
'CakephpFixtureFactories' => [
    'onlyDebug' => true,
    'optional' => true,
],
```

Baking factories requires the CakePHP Bake plugin. If your application does not already include it, install and load it:

```bash
composer require --dev cakephp/bake:^4.0@dev
bin/cake plugin load --only-debug Bake
```

## Prepare the test database

Configure a separate `test` connection in your application and create its schema using your application's migrations. Factories create records in existing tables; they do not create the schema. The [CakePHP testing guide](https://book.cakephp.org/6/en/development/testing.html#test-database-setup) explains test connection aliases. [Setup](/guide/setup) covers migration and transaction cleanup options.

Continue with [Getting started](/guide/) to bake and run one factory.

## Development test dependencies

The plugin's own test suite currently uses the reviewed [CakePHP 6 test-suite-light fork](https://github.com/vierge-noire/cakephp-test-suite-light/pull/75). Its CI adds that VCS repository during dependency installation; applications using factories do not need this test dependency.

The test suite also temporarily uses Migrations `dev-fix/6.x-cakephp-compatibility` while [Migrations #1121](https://github.com/cakephp/migrations/pull/1121) is pending.
