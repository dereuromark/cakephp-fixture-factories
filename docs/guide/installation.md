# Installation

Install the plugin and one data generator in your CakePHP application:

```bash
composer require --dev dereuromark/cakephp-fixture-factories fakerphp/faker
```

Faker works with the plugin's PHP 8.2 minimum. You can instead install `johnykvsky/dummygenerator` on PHP 8.3 or newer; see [Generators](/guide/generators). The plugin auto-detects the installed generator, preferring Faker when both are present.

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
composer require --dev cakephp/bake
bin/cake plugin load --only-debug Bake
```

## Prepare the test database

Configure a separate `test` connection in your application and create its schema using your application's migrations. Factories create records in existing tables; they do not create the schema. The [CakePHP testing guide](https://book.cakephp.org/5/en/development/testing.html#test-database-setup) explains test connection aliases. [Setup](/guide/setup) covers migration and transaction cleanup options.

Continue with [Getting started](/guide/) to bake and run one factory.
