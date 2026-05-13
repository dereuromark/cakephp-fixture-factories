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
| `--all-fields` | Emit default values for **every** non-primary, non-foreign-key column — including nullable columns and columns with a DB default. Without the flag, bake only fills required (NOT NULL, no DB default) columns. |
| `-p Plugin` | Bake into a plugin's namespace |
| `-h`        | Show all available options |

### `--all-fields`: include optional columns

By default, the baked `definition()` only emits values for columns the database would otherwise reject — required, no-default, non-FK columns. Optional columns and columns that the database already defaults are skipped to keep the factory minimal.

Pass `--all-fields` when you want a fully populated factory body up front:

```bash
bin/cake bake fixture_factory Articles --all-fields
```

Foreign-key columns remain excluded regardless of the flag, so the baked factory keeps pushing related rows through the association APIs (`with()` / `for()` / `has()`) instead of emitting raw integer FK values.

## Customizing the generated code

Two configuration keys influence what `bake` writes:

- **`defaultDataMap`** — map column types to generator method fragments, call fragments with arguments, or `$generator->...` calls.
- **`columnPatterns`** — map column-name regexes to generator method fragments, call fragments with arguments, or `$generator->...` calls.

Both let you teach `bake` what `phone`, `zip`, `email`, etc. should look like in your domain. See the [Configuration Reference](configuration#defaultdatamap).

```php
// config/app.php
'FixtureFactories' => [
    'columnPatterns' => [
        '/^phone/' => 'phoneNumber()',
        '/^zip/'   => 'postcode()',
    ],
],
```

After regenerating, fields named `phone_*` and `zip_*` will be filled with the matching generator method instead of a generic string.
