<p align="center">
    <a href="https://vierge-noire.github.io/" target="_blank"><img src="https://vierge-noire.github.io/images/fixture_factories.svg" alt="ff-logo" width="150"  /></a>
</p>
<h1 align="center">
CakePHP Fixture Factories
</h1>
<h3 align="center">
Write and run your tests faster. On any PHP application.
</h3>

<p align="center">
    <a href="https://github.com/dereuromark/cakephp-fixture-factories/actions/workflows/ci.yml?query=branch%3Amain"><img src="https://github.com/dereuromark/cakephp-fixture-factories/actions/workflows/ci.yml/badge.svg?branch=main" alt="Build Status"></a>
    <a href="https://codecov.io/gh/dereuromark/cakephp-fixture-factories"><img src="https://codecov.io/gh/dereuromark/cakephp-fixture-factories/branch/main/graph/badge.svg" alt="Coverage Status"></a>
    <a href="https://packagist.org/packages/dereuromark/cakephp-fixture-factories"><img src="https://poser.pugx.org/dereuromark/cakephp-fixture-factories/v/stable.svg" alt="Latest Stable Version"></a>
    <a href="https://php.net/"><img src="https://img.shields.io/badge/php-%3E%3D%208.1-8892BF.svg" alt="Minimum PHP Version"></a>
    <a href="LICENSE"><img src="https://poser.pugx.org/dereuromark/cakephp-fixture-factories/license.svg" alt="License"></a>
    <a href="https://packagist.org/packages/dereuromark/cakephp-fixture-factories"><img src="https://poser.pugx.org/dereuromark/cakephp-fixture-factories/d/total.svg" alt="Total Downloads"></a>
    <a href="https://github.com/cakephp/cakephp-codesniffer"><img src="https://img.shields.io/badge/cs-CakePHP-yellow.svg" alt="Coding Standards"></a>
</p>

Note: This is a maintained fork of the original [CakePHPFixtureFactories](https://github.com/vierge-noire/cakephp-fixture-factories).

---

```php
ArticleFactory::make(5)
    ->with('Authors[3].Address.City.Country')
    ->persist();
```

---

## Installation
```
composer require --dev dereuromark/cakephp-fixture-factories
```


## Content

* ### [Setup - DB Cleaning](docs/setup.md)
* ### [Fixture Factories](docs/factories.md)
* ### [Test Fixtures](docs/examples.md)
* ### [Associations](docs/associations.md)
* ### [Associations for non-CakePHP apps](docs/no_cake_associations.md)
* ### [Scenarios](docs/scenarios.md)
* ### [Queries](docs/queries.md)
* ### [Bake command](docs/bake.md)
* ### [Persist command](docs/commands.md)
* ### [Generator Abstraction](docs/generator-differences.md)

---


## Resources

[CakeFest 2021](https://www.youtube.com/watch?v=1WrWH2F_hWE) -
[IPC-Berlin 2020](https://www.youtube.com/watch?v=yJ6EqAE2NEs) -
[CakeFest 2020](https://www.youtube.com/watch?v=PNA1Ck2-nVc&t=30s)

## Contribute

Send PRs or tickets in GitHub.

## Authors
Previously, Juan Pablo Ramirez and Nicolas Masson.
This fork is maintained by Mark Scherer (dereuromark)

## License

The CakePHPFixtureFactories plugin is offered under an [MIT license](https://opensource.org/licenses/mit-license.php).

Copyright 2023 Juan Pablo Ramirez and Nicolas Masson

Licensed under The MIT License Redistributions of files must retain the above copyright notice.
