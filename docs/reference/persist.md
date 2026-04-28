# Persist command

Populate the database from the CLI — useful for manually browsing the app with realistic data, or seeding a dev environment.

## Usage

```bash
bin/cake fixture_factories persist <Model> [options]
```

Example: persist 5 articles, each with 3 Irish authors, by calling the `withThreeIrishAuthors()` factory method:

```bash
bin/cake fixture_factories persist Articles -n 5 -m withThreeIrishAuthors
```

## Arguments

- `<Model>` — the model name in PascalCase. The plugin resolves it to the matching factory in `App\Test\Factory` (configurable via `FixtureFactories.testFixtureNamespace`).

## Options

| Flag                 | Effect |
|----------------------|--------|
| `-n`, `--number`     | How many entities to persist. Default: `1` |
| `-m`, `--method`     | A factory method to apply (e.g. `withThreeIrishAuthors`) |
| `-c`, `--connection` | Connection to persist into. Default: `test` |
| `-w`, `--with`       | Also create associated fixtures |
| `-d`, `--dry-run`    | Print what would happen without writing to the DB |

## Tips

- For complex setups, prefer wrapping logic in a [scenario](/guide/scenarios) and persisting that — much easier to reuse than long CLI flags.
- Use `-c default` (or any non-`test` connection) when seeding a dev database; `test` is the default to avoid accidentally polluting production-shaped DBs.
