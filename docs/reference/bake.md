# Bake command

Generate factory classes from your existing schema. Recommended over hand-writing them.

## Loading the plugin

In `src/Application.php`:

```php
$this->addPlugin('CakephpFixtureFactories');
```

See the cookbook for [how to load a plugin](https://book.cakephp.org/5/en/plugins.html#loading-a-plugin).

## Usage

```bash
bin/cake bake fixture_factory -h
```

shows all options. Common patterns:

```bash
# One factory at a time
bin/cake bake fixture_factory Articles

# All models in your app
bin/cake bake fixture_factory -a

# Include association helpers (->hasAuthors(), ->forCountry(), …)
bin/cake bake fixture_factory Articles -m

# Bake into a plugin's namespace
bin/cake bake fixture_factory Articles -p MyPlugin
```

## Options

| Flag        | Effect |
|-------------|--------|
| `-a`        | Bake every model in the app |
| `-m`        | Add directional helpers based on associations (`for…()` for to-one, `has…()` for to-many) |
| `-p Plugin` | Bake into a plugin's namespace |
| `-h`        | Show all available options |

## Customizing the generated code

Two configuration keys influence what `bake` writes:

- **`defaultDataMap`** — map column types to generator method calls.
- **`columnPatterns`** — map column-name regexes to generator method calls.

Both let you teach `bake` what `phone`, `zip`, `email`, etc. should look like in your domain. See the [Configuration Reference](configuration#defaultdatamap).

```php
// config/app.php
'FixtureFactories' => [
    'columnPatterns' => [
        '/^phone/' => '$generator->phoneNumber()',
        '/^zip/'   => '$generator->postcode()',
    ],
],
```

After regenerating, fields named `phone_*` and `zip_*` will be filled with the matching generator method instead of a generic string.
