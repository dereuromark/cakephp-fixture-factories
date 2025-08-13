# Upgrade Guide to v4

## Overview

CakePHP Fixture Factories v4 is a complete rewrite focusing on simplicity and developer experience. The codebase has been reduced by **80%** while maintaining all essential functionality.

### Key Improvements
- **80% less code** (1,800 → 400 lines)
- **73% fewer public methods** (30+ → 8)
- **Clearer, more intuitive API**
- **Better IDE support**
- **Faster execution**

## Breaking Changes

### Removed Classes
- `DataCompiler`
- `AssociationBuilder`
- `EventCollector`
- `UniquenessJanitor`

### Core API Changes

#### New Simplified API
```php
ArticleFactory::make($data)       // Start factory
    ->times($n)                   // Set count
    ->with($dataOrCallback)       // Add data
    ->withField($name, $value)    // Add single field
    ->withAssoc($name, $config)   // Add associations
    ->withoutAssoc($name)         // Remove associations
    ->transient()                 // Skip persistence
    ->create();                   // Execute (terminal)
```

#### Method Replacements

| v3 Method | v4 Replacement          |
|-----------|-------------------------|
| `setTimes($n)` | `times($n)`             |
| `patchData($data)` | `with($data)`           |
| `setField($k, $v)` | `with([$k => $v])`      |
| `without($assoc)` | `withoutAssoc($assoc)` |
| `getEntity()` | `transient()->create()` |
| `getEntities()` | `transient()->create()` |
| `persist()` | `create()`              |

## Migration Examples

### Basic Entity Creation

**Before (v3):**
```php
$article = ArticleFactory::make(['title' => 'Test'])->persist();
$article = ArticleFactory::make()->patchData(['title' => 'Test'])->persist();
$article = ArticleFactory::make()->getEntity(); // without persist
```

**After (v4):**
```php
$article = ArticleFactory::make(['title' => 'Test'])->create();
$article = ArticleFactory::make()->with(['title' => 'Test'])->create();
$article = ArticleFactory::make()->transient()->create(); // without persist
```

### Multiple Entities

**Before (v3):**
```php
$articles = ArticleFactory::make(5)->persist();
$articles = ArticleFactory::make([], 5)->persist();
```

**After (v4):**
```php
$articles = ArticleFactory::make()->times(5)->create();
```

### Associations

**Before (v3):**
```php
$article = ArticleFactory::make()
    ->with('Authors', AuthorFactory::make(2))
    ->with('Categories', ['name' => 'Tech'])
    ->without('Comments')
    ->persist();
```

**After (v4):**
```php
$article = ArticleFactory::make()
    ->withAssoc('Authors', AuthorFactory::make(2))
    ->withAssoc('Categories', ['name' => 'Tech'])
    ->withoutAssoc('Comments')
    ->create();
```

## Factory Definition Updates

### Template Method

**Before (v3):**
```php
protected function setDefaultTemplate(): void
{
    $this->setDefaultData(function (GeneratorInterface $generator) {
        return [
            'title' => $generator->text(120),
        ];
    })
    ->withAuthors(null, self::DEFAULT_NUMBER_OF_AUTHORS);
}

public function withAuthors($parameter = null, int $n = 1): static
{
    return $this->with('Authors', AuthorFactory::make($parameter, $n));
}
```

**After (v4):**
```php
protected function setDefaultTemplate(): void
{
    $this->setDefaultData(fn(GeneratorInterface $g) => [
        'title' => $g->text(120),
    ]);

    // Set default associations
    $this->withAssoc('Authors', 2);
}

// Custom helper methods are simpler
public function withTitle(string $title): static
{
    return $this->with(['title' => $title]);
}
```

## Query Methods

Query methods have been moved to a separate class:

**Before (v3):**
```php
$article = ArticleFactory::find($id);
$count = ArticleFactory::count();
```

**After (v4):**
```php
$article = ArticleFactory::find($id);  // Still works
$count = ArticleFactory::count();       // Still works

// Or use the new query() method for more options
$query = ArticleFactory::query();
$article = $query->find($id);
$count = $query->count();
$random = $query->random();
```

## Return Type Changes

v4 has predictable return types:

- `create()` returns single entity when count=1, array otherwise
- `createSet()` always returns ResultSet
- No more confusion between Entity/array/ResultSet

## Quick Migration Checklist

1. **Update method calls:**
   - [ ] Replace `setTimes()` with `times()`
   - [ ] Replace `patchData()` with `with()`
   - [ ] Replace `persist()` with `create()`
   - [ ] Replace `getEntity()` with `transient()->create()`

2. **Update factory definitions:**
   - [ ] Simplify `setDefaultTemplate()`
   - [ ] Update custom helper methods

3. **Update associations:**
   - [ ] Replace `with()` for associations with `withAssoc()`
   - [ ] Simplify custom association methods

4. **Fix imports:**
   - [ ] Remove imports for deleted classes
   - [ ] Add `use CakephpFixtureFactories\Factory\FactoryQuery;` if needed

## Benefits After Upgrading

- **Faster test writing** - Intuitive API means less documentation lookups
- **Easier debugging** - Simpler stack traces
- **Better maintainability** - 80% less code to understand
- **Improved performance** - Fewer object allocations
- **Clearer intent** - Methods do exactly what they say

## Need Help?

If you encounter issues:
1. Check this guide for your specific use case
2. Review the test failures - they often show exactly what needs changing
3. Open an issue on GitHub with your specific scenario
