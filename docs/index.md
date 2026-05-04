---
layout: home

hero:
  name: CakePHP Fixture Factories
  text: Write and run your tests faster.
  tagline: Build expressive test fixtures with associations, scenarios, and pluggable data generators — for CakePHP and beyond.
  image:
    src: /logo.svg
    alt: CakePHP Fixture Factories
  actions:
    - theme: brand
      text: Get Started
      link: /guide/
    - theme: alt
      text: View on GitHub
      link: https://github.com/dereuromark/cakephp-fixture-factories

features:
  - icon: |
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
    title: Faster Tests, Less Boilerplate
    details: Skip $fixtures arrays. The Factory Transaction Strategy wraps each test, rolls back automatically, and resets generator state.
  - icon: |
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="2" x2="6" y2="9"/><line x1="18" y1="2" x2="18" y2="9"/><path d="M5 9h14a1 1 0 0 1 1 1v4a6 6 0 0 1-6 6h-4a6 6 0 0 1-6-6v-4a1 1 0 0 1 1-1z"/><line x1="12" y1="20" x2="12" y2="22"/></svg>
    title: Pluggable Generators
    details: Pick Faker (mature, full locales) or DummyGenerator (lean, PHP 8.3+, native enums). Or plug in your own.
  - icon: |
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="12" cy="18" r="3"/><path d="M6 9v3a3 3 0 0 0 3 3h6a3 3 0 0 0 3-3V9"/><path d="M12 12v3"/></svg>
    title: Deep Associations
    details: One fluent expression builds the whole object graph — Articles → Authors → Address → City → Country, persisted in one go.
  - icon: |
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
    title: Bake Your Factories
    details: Generate factories from your schema with smart, configurable type guessing per column name and type.
  - icon: |
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 4 4 5-5"/></svg>
    title: Reusable Scenarios
    details: Encapsulate complex test data — "10 Australian authors", "a checkout in progress" — and load them in any test.
  - icon: |
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
    title: PHPStan Level 8
    details: Strict generic type annotations across the codebase. Your factories pick up types correctly in any IDE.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: -webkit-linear-gradient(120deg, #d33c43 30%, #f59e0b);
}
.VPHero .image-container {
  max-width: 280px;
  max-height: 280px;
}
.VPHero .image-container img {
  max-width: 100%;
  max-height: 100%;
}
</style>

## Quick Example

```php
ArticleFactory::make(5)
    ->with('Authors[3].Address.City.Country')
    ->persistMany();
```

Five articles, each with three authors, each with an address, city and country — persisted in one expression.

## Installation

```bash
composer require --dev dereuromark/cakephp-fixture-factories
```

Then load the plugin and configure the fixture strategy. See [Setup](/guide/setup) for full instructions.

## Migrating from vierge-noire?

This is a maintained fork of [vierge-noire/cakephp-fixture-factories](https://github.com/vierge-noire/cakephp-fixture-factories).
The main breaking change is the generator abstraction — see the short [migration guide](/guide/migration).
