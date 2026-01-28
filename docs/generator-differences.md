# Generator Differences

This document provides a detailed comparison between the available fake data generators and a migration guide for switching between them.

## Available Generators

### Faker (Default)
- **Package**: [FakerPHP/Faker](https://github.com/FakerPHP/Faker)
- **Minimum PHP**: 7.4+ (8.2+ in our scope)
- **Install**: `composer require --dev fakerphp/faker`
- **Best for**: Mature, feature-rich data generation with extensive locale support

### Dummy
- **Package**: [johnykvsky/dummygenerator](https://github.com/johnykvsky/dummygenerator)
- **Minimum PHP**: 8.3+
- **Install**: `composer require --dev johnykvsky/dummygenerator`
- **Best for**: Modern PHP 8.3+ projects, native enum support, lightweight footprint

## Key Differences

### 1. Enum Support

**Faker** (requires shim methods):
```php
// Get random enum case (returns the enum object)
$status = $this->getGenerator()->enumCase(TestStatus::class);

// Get random backed enum value (returns string/int)
$statusValue = $this->getGenerator()->enumValue(TestStatus::class);
```

**DummyGenerator** (native support):
```php
// Get random enum case (native method)
$status = $this->getGenerator()->enumCase(TestStatus::class);

// Get random backed enum value
$statusValue = $this->getGenerator()->enumValue(TestStatus::class);
```

### 2. Locale Support

**Faker**:
- Full locale support with locale-specific data
- 50+ locales available
- Example: `CakeGeneratorFactory::create('fr_FR')`

**DummyGenerator**:
- Limited locale support
- Parameter is accepted for interface compatibility but may not affect output
- Focuses on generic data generation

### 3. Method Compatibility

Most methods work identically across both generators. However, some methods are shimmed for compatibility:

| Method | Faker | DummyGenerator | Notes |
|--------|-------|----------------|-------|
| `uuid()` | Native | Mapped to `uuid4()` | Both generate valid UUIDs |
| `realText()` | Native | Mapped to `text()` | Same behavior |
| `randomAscii()` | Native | Shimmed | Returns ASCII character (33-126) |
| `enumCase()` | Shimmed | Native | Recommended for enums |
| `enumValue()` | Shimmed | Shimmed | Works on both |

### 4. Seeding Behavior

Both generators support seeding for reproducible test data:

**Faker**:
```php
$generator->seed(1234);
```

**DummyGenerator**:
```php
$generator->seed(1234); // Uses XoshiroRandomizer internally
```

### 5. Performance

**Faker**:
- Mature and well-tested
- Larger footprint
- More features and providers

**DummyGenerator**:
- Modern PHP 8.3+ optimizations
- Leaner codebase
- Faster for basic operations

## Switching Generators

### Configuration

Set the generator type in your `tests/bootstrap.php` or test configuration:

```php
use Cake\Core\Configure;

// Use Faker (default)
Configure::write('FixtureFactories.generatorType', 'faker');

// Use DummyGenerator
Configure::write('FixtureFactories.generatorType', 'dummy');
```

Or set it per-factory:

```php
use CakephpFixtureFactories\Factory\BaseFactory;

class ArticleFactory extends BaseFactory
{
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(function() {
            return [
                'title' => $this->getGenerator()->setGenerator('dummy')->sentence(),
            ];
        });
    }
}
```

### Migration Guide: Faker → DummyGenerator

**Step 1: Install DummyGenerator**
```bash
composer require --dev johnykvsky/dummygenerator
```

**Step 2: Update Configuration**
```php
// In tests/bootstrap.php
Configure::write('FixtureFactories.generatorType', 'dummy');
```

**Step 4: Test Your Factories**

Run your test suite to ensure compatibility:
```bash
vendor/bin/phpunit
```

**Step 5: (Optional) Remove Faker**

If you no longer need Faker:
```bash
composer remove --dev fakerphp/faker
```

### Migration Guide: DummyGenerator → Faker

**Step 1: Install Faker**
```bash
composer require --dev fakerphp/faker
```

**Step 2: Update Configuration**
```php
// In tests/bootstrap.php
Configure::write('FixtureFactories.generatorType', 'faker');
```

**Step 3: Update Enum Methods (if using)**

`enumCase()` and `enumValue()` work on both, so no changes needed unless you're using DummyGenerator-specific features.

**Step 4: Adjust for Locale (if needed)**

Take advantage of Faker's locale support:
```php
$generator = CakeGeneratorFactory::create('fr_FR');
```

**Step 5: Test Your Factories**

Run your test suite:
```bash
vendor/bin/phpunit
```

## Common Pitfalls

### 1. PHP Version Requirements

**Problem**: DummyGenerator requires PHP 8.3+

**Solution**: Check your PHP version before switching:
```bash
php -v
```

### 2. Locale-Specific Data

**Problem**: DummyGenerator doesn't support locale-specific data like Faker

**Solution**: If you need locale-specific data, stick with Faker or implement custom data providers

### 3. Unique Values

**Problem**: Different retry mechanisms for unique values

**Solution**: Both support `unique()` modifier, but DummyGenerator has a custom retry mechanism (10,000 attempts vs Faker's default)

```php
// Works on both
$generator->unique()->email();
```

## Best Practices

### 1. Keep Factory Code Generator-Agnostic

Write factory code that works with both generators:

```php
// Good - works with both
'email' => $this->getGenerator()->email(),
'name' => $this->getGenerator()->name(),
'status' => $this->getGenerator()->enumCase(TestStatus::class),

// Avoid generator-specific methods unless necessary
```

### 2. Use Configuration for Switching

Don't hardcode generator selection in factories:

```php
// Bad
$this->getGenerator()->setGenerator('dummy');

// Good - use configuration
Configure::write('FixtureFactories.generatorType', 'dummy');
```

### 3. Test with Both Generators (Optional)

If your library supports multiple PHP versions, test with both:

```yaml
# .github/workflows/ci.yml
matrix:
  php: ['8.1', '8.2', '8.3']
  generator: ['faker', 'dummy']
  exclude:
    - php: '8.1'
      generator: 'dummy'
    - php: '8.2'
      generator: 'dummy'
```

### 4. Seed for Reproducible Tests

When debugging, use seeding for reproducible data:

```php
public function testSomething(): void
{
    $this->getGenerator()->seed(1234);

    // Test with predictable data
    $article = ArticleFactory::make()->getEntity();
}
```

## Feature Matrix

| Feature | Faker | DummyGenerator |
|---------|-------|----------------|
| Basic data types | ✅ | ✅ |
| Text generation | ✅ | ✅ |
| Date/time | ✅ | ✅ |
| Internet (email, URL) | ✅ | ✅ |
| Person (name, title) | ✅ | ✅ |
| Address | ✅ | ✅ |
| Phone numbers | ✅ | ✅ |
| Company | ✅ | ✅ |
| UUID | ✅ | ✅ |
| Colors | ✅ | ✅ |
| Credit cards | ✅ | ✅ |
| Barcodes (EAN, ISBN) | ✅ | ✅ |
| Locale support | ✅ Full | ⚠️ Limited |
| Native enum support | ⚠️ Shimmed | ✅ Native |
| `unique()` modifier | ✅ | ✅ |
| `optional()` modifier | ✅ | ✅ |
| Seeding | ✅ | ✅ |
| PHP 8.0+ | ✅ | ❌ |
| PHP 8.3+ | ✅ | ✅ Required |

## Performance Comparison

While both generators are fast enough for testing purposes, here's a general comparison:

**Faker**:
- Mature codebase with extensive providers
- ~5-10ms average per factory creation
- Larger memory footprint

**DummyGenerator**:
- Modern PHP 8.3+ optimizations
- ~3-7ms average per factory creation
- Smaller memory footprint
- Native enum handling is faster

> **Note**: Performance differences are typically negligible for test suites. Choose based on features and PHP version requirements rather than performance.

## Troubleshooting

### "Class not found" errors

**Faker not found**:
```
Faker library is not installed. Please install it using: `composer require --dev fakerphp/faker`
```

**DummyGenerator not found**:
```
DummyGenerator library is not installed. Please install it using: `composer require --dev johnykvsky/dummygenerator`
```

**Solution**: Install the required package.

### Method not found

**Problem**: A method works with one generator but not the other

**Solution**: Check the compatibility table above. Most methods are shimmed, but some advanced features may differ.

## Additional Resources

- [Faker Documentation](https://fakerphp.github.io/)
- [DummyGenerator Documentation](https://github.com/johnykvsky/dummygenerator)
- [GeneratorInterface Source](../src/Generator/GeneratorInterface.php)
- [Factory Documentation](factories.md)

## FAQ

### Which generator should I use?

- **Use Faker** if you need extensive locale support or support PHP < 8.3
- **Use DummyGenerator** if you're on PHP 8.3+ and want a modern, lean solution with native enum support

### Can I use both generators in the same project?

Yes! You can configure different generators per-factory or switch globally. However, it's recommended to use one consistently for maintainability.

### Will my existing factories break if I switch?

Most likely not. The plugin provides compatibility shims for common methods. However, test your factories after switching.

### How do I contribute new methods?

Add them to `GeneratorInterface` and implement in both `FakerAdapter` and `DummyGeneratorAdapter`, ensuring compatibility across both generators.

### Can I create a custom generator?

Yes! Implement `GeneratorInterface` and register it:

```php
use CakephpFixtureFactories\Generator\CakeGeneratorFactory;

CakeGeneratorFactory::registerAdapter('custom', MyCustomAdapter::class);

Configure::write('FixtureFactories.generatorType', 'custom');
```
