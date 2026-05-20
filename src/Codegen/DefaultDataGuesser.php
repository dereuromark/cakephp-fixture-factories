<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Codegen;

use Cake\Core\Configure;
use Cake\ORM\Behavior\TimestampBehavior;
use Cake\ORM\Table;

/**
 * Generates the body of a baked factory's `definition()` method.
 *
 * Walks a `Table`'s schema and emits a `column => '$generator->...'` map
 * for every non-primary, non-foreign-key, NOT NULL column with no DB default
 * — i.e. the columns most likely to need fixture-time values.
 *
 * Behavior is configurable via two `FixtureFactories.*` Configure keys:
 *
 * - `defaultDataMap`: nested `[type => [column => method]]` overrides merged
 *   on top of the bundled mapping. Values may be shorthand method names
 *   (`'ean13'`), method calls (`'randomElement([1, 2])'`) or full
 *   generator calls (`'$generator->phoneNumber()'`).
 * - `columnPatterns`: `[regex => method]` map evaluated *before* the type
 *   table — first match wins. Useful for cross-cutting suffix conventions
 *   like `_at => optional(0.7)->dateTime()`.
 *
 * Extracted from {@see \CakephpFixtureFactories\Command\BakeFixtureFactoryCommand}
 * so the logic is independently testable without instantiating the command.
 */
class DefaultDataGuesser
{
    /**
     * Built-in mappings: `[column-type => [column-name => generator-method]]`.
     *
     * @var array<string, array<string, string>>
     */
    protected array $map = [
        'string' => [
            'name' => 'name',
            'first_name' => 'firstName',
            'last_name' => 'lastName',
            'username' => 'userName',
            'slug' => 'slug',
            'email' => 'email',
            'description' => 'words',
            'postal_code' => 'postcode',
            'city' => 'city',
            'address' => 'address',
            'street_name' => 'streetName',
            'street_address' => 'streetAddress',
            'url' => 'url',
            'website' => 'url',
            'link' => 'url',
            'ip_address' => 'ipv4',
            'currency' => 'currencyCode',
            'phone_number' => 'phoneNumber',
            'timezone' => 'timezone',
            'title' => 'sentence',
            'bio' => 'realText',
            'biography' => 'realText',
            'country_code' => 'countryCode',
            'language' => 'languageCode',
            'language_code' => 'languageCode',
            'locale' => 'locale',
            'status' => "randomElement(['active', 'inactive', 'pending'])",
            'gender' => "randomElement(['M', 'F', 'Other'])",
            'company' => 'company',
            'company_name' => 'company',
            'job_title' => 'jobTitle',
            'mime_type' => 'mimeType',
            'file_extension' => 'fileExtension',
            'color' => 'colorName',
            'hex_color' => 'hexColor',
        ],
        'float' => [
            'latitude' => 'latitude',
            'longitude' => 'longitude',
            'price' => 'randomFloat(2, 0, 1000)',
            'cost' => 'randomFloat(2, 0, 1000)',
            'amount' => 'randomFloat(2, 10, 5000)',
            'total' => 'randomFloat(2, 10, 5000)',
            'percentage' => 'randomFloat(2, 0, 100)',
            'rate' => 'randomFloat(4, 0, 1)',
            'discount' => 'randomFloat(2, 0, 50)',
            'tax' => 'randomFloat(2, 0, 30)',
        ],
        'integer' => [
            'age' => 'numberBetween(18, 80)',
            'year' => 'year',
            'quantity' => 'numberBetween(1, 100)',
            'count' => 'numberBetween(0, 1000)',
            'views' => 'numberBetween(0, 10000)',
            'likes' => 'numberBetween(0, 5000)',
            'rating' => 'numberBetween(1, 5)',
            'score' => 'numberBetween(0, 100)',
            'position' => 'numberBetween(1, 100)',
            'order' => 'numberBetween(1, 100)',
        ],
    ];

    /**
     * Column types this guesser knows how to produce a default for. Anything
     * outside this list is skipped (no fixture data emitted).
     *
     * @var array<string>
     */
    protected array $supportedTypes = [
        'integer', 'string', 'date', 'datetime', 'time',
        'boolean', 'float', 'decimal', 'uuid', 'json', 'text',
    ];

    /**
     * When true, the guesser also emits defaults for nullable columns and
     * columns with a DB default. Backs the `bake fixture_factory --all-fields`
     * flag. Foreign-key and primary-key columns are still skipped regardless,
     * so the baked factory keeps pushing users toward association-aware APIs.
     */
    protected bool $includeOptional = false;

    /**
     * Replace the entire bundled mapping. Useful for projects whose
     * conventions diverge enough that a partial merge would be noisy.
     *
     * @param array<string, array<string, string>> $map New mapping.
     */
    public function setMap(array $map): static
    {
        $this->map = $map;

        return $this;
    }

    /**
     * Layer additional `[type => [column => method]]` entries on top of the
     * bundled mapping without replacing it. Equivalent to setting
     * `FixtureFactories.defaultDataMap` in Configure but scoped to one
     * guesser instance.
     *
     * @param array<string, array<string, string>> $map Additional entries.
     */
    public function mergeMap(array $map): static
    {
        $this->map = array_replace_recursive($this->map, $map);

        return $this;
    }

    /**
     * Toggle the "include optional columns" mode.
     *
     * When `true`, the guesser emits defaults for nullable columns and
     * columns with a DB default — useful when you want bake to pre-populate
     * `definition()` with every interesting column up front. Backs the
     * `bake fixture_factory --all-fields` flag.
     *
     * Foreign-key columns and primary keys are still excluded regardless so
     * the baked factory keeps pushing users toward `with()` / `for()` /
     * `has()` for related rows.
     */
    public function setIncludeOptional(bool $include): static
    {
        $this->includeOptional = $include;

        return $this;
    }

    /**
     * Build the `column => generator-expression` map for a Table's schema.
     *
     * @param \Cake\ORM\Table $table The Table being baked against.
     *
     * @return array<string, string> Column name → PHP expression as a string.
     */
    public function guessFor(Table $table): array
    {
        $defaultData = [];
        $modelName = $table->getAlias();
        $schema = $table->getSchema();
        $columns = $schema->columns();
        $foreignKeys = $this->collectForeignKeys($table);
        $uniqueFields = $this->collectSingleColumnUniqueFields($table);
        $primaryKeys = $schema->getPrimaryKey();
        $behaviorManaged = $this->collectBehaviorManagedFields($table);

        foreach ($columns as $column) {
            if (in_array($column, $primaryKeys, true) || in_array($column, $foreignKeys, true)) {
                continue;
            }
            if (in_array($column, $behaviorManaged, true)) {
                // Field is populated automatically at save time by a Cake
                // behavior (e.g. `TimestampBehavior` for `created` /
                // `modified`). Baking a generator value would land in the
                // entity, make the field dirty, and the behavior would then
                // skip its own update — producing random fixture timestamps
                // instead of the test-run's "now". Skip so the behavior runs.
                continue;
            }

            $columnSchema = $schema->getColumn($column);
            if (!$columnSchema) {
                continue;
            }
            if (!$this->includeOptional && ($columnSchema['null'] || $columnSchema['default'] !== null)) {
                continue;
            }

            if (!in_array($columnSchema['type'], $this->supportedTypes, true)) {
                continue;
            }

            $guessed = $this->guess($column, $modelName, $columnSchema);
            if ($guessed === null || $guessed === '') {
                continue;
            }

            // Wrap with ->unique()-> for fields that have a uniqueness constraint
            // or unique index, so the bake output won't collide on its own
            // generated values.
            if (in_array($column, $uniqueFields, true) && str_starts_with($guessed, '$generator->')) {
                $guessed = preg_replace('/^\$generator->/', '$generator->unique()->', $guessed) ?? $guessed;
            }

            $defaultData[$column] = $guessed;
        }

        return $defaultData;
    }

    /**
     * Resolve a single column to a generator expression, or `null` to skip.
     *
     * @param string $column Column name (from schema).
     * @param string $modelName Table alias (PascalCase).
     * @param array<string, mixed> $columnSchema Cake schema metadata for the column.
     */
    public function guess(string $column, string $modelName, array $columnSchema): ?string
    {
        // Project-supplied user maps merged on top of the bundled defaults.
        // Use array_replace_recursive: the leaves are scalar generator
        // expressions, and array_merge_recursive would collapse duplicate
        // keys into [old, new] arrays which silently corrupt the output.
        $map = array_replace_recursive($this->map, (array)Configure::read('FixtureFactories.defaultDataMap'));

        // Pattern overrides win over per-type lookups so users can short-
        // circuit cross-cutting suffix/prefix conventions.
        /** @var array<string, string> $customPatterns */
        $customPatterns = (array)Configure::read('FixtureFactories.columnPatterns', []);
        foreach ($customPatterns as $pattern => $generatorMethod) {
            if (preg_match($pattern, $column)) {
                return $this->normalizeGeneratorExpression($generatorMethod);
            }
        }

        // Bundled suffix conventions.
        if (str_ends_with($column, '_at') && $columnSchema['type'] === 'datetime') {
            return '$generator->optional(0.7)->dateTime()';
        }
        if (str_ends_with($column, '_count') && $columnSchema['type'] === 'integer') {
            return '$generator->numberBetween(0, 100)';
        }

        $typeMap = $map[$columnSchema['type']] ?? [];

        return match ($columnSchema['type']) {
            'string' => $this->guessString($column, $modelName, $typeMap, $columnSchema),
            'integer' => $this->guessFromMapOrFallback($column, $typeMap, '$generator->randomNumber()'),
            'boolean', 'bool' => $this->guessFromMapOrFallback($column, $typeMap, '$generator->boolean()'),
            'float' => $this->guessFloat($column, $typeMap),
            'decimal' => $this->guessDecimal($column, $columnSchema),
            'uuid' => '$generator->uuid()',
            'json' => '["key" => $generator->word(), "value" => $generator->randomNumber()]',
            'text' => $this->guessText($column),
            'date' => $this->guessFromMapOrFallback($column, $typeMap, '$generator->date()'),
            'datetime' => $this->guessFromMapOrFallback($column, $typeMap, '$generator->datetime()'),
            'time' => $this->guessFromMapOrFallback($column, $typeMap, '$generator->time()'),
            default => null,
        };
    }

    /**
     * @param string $column
     * @param array<string, string> $typeMap
     * @param string $fallback
     */
    private function guessFromMapOrFallback(string $column, array $typeMap, string $fallback): string
    {
        if (isset($typeMap[$column])) {
            return $this->normalizeGeneratorExpression($typeMap[$column]);
        }

        return $fallback;
    }

    /**
     * @param string $column
     * @param string $modelName
     * @param array<string, string> $typeMap
     * @param array<string, mixed> $columnSchema
     */
    private function guessString(string $column, string $modelName, array $typeMap, array $columnSchema): string
    {
        $modelNameMap = [
            'Countries' => 'country',
            'Cities' => 'city',
        ];

        if ($column === 'name' && isset($modelNameMap[$modelName])) {
            return $this->normalizeGeneratorExpression($modelNameMap[$modelName]);
        }
        if (isset($typeMap[$column])) {
            return $this->normalizeGeneratorExpression($typeMap[$column]);
        }

        // Smart string-length handling: keep generated text within the column.
        $length = $columnSchema['length'] ?? 255;
        if ($length <= 3) {
            return '$generator->lexify("' . str_repeat('?', (int)$length) . '")';
        }
        if ($length <= 10) {
            return '$generator->word()';
        }
        if ($length <= 50) {
            return 'implode(" ", $generator->words(3))';
        }
        if ($length <= 100) {
            return '$generator->sentence()';
        }

        return '$generator->text(' . (int)$length . ')';
    }

    /**
     * @param string $column
     * @param array<string, string> $typeMap
     */
    private function guessFloat(string $column, array $typeMap): string
    {
        if (isset($typeMap[$column])) {
            return $this->normalizeGeneratorExpression($typeMap[$column]);
        }

        return '$generator->randomFloat(2, 0, 100)';
    }

    /**
     * @param string $column
     * @param array<string, mixed> $columnSchema
     */
    private function guessDecimal(string $column, array $columnSchema): string
    {
        $precision = (int)($columnSchema['precision'] ?? 10);
        $scale = (int)($columnSchema['scale'] ?? 2);
        // Largest representable integer part for this precision/scale pair.
        $max = (string)((10 ** ($precision - $scale)) - 1);

        if (str_contains($column, 'price') || str_contains($column, 'cost') || str_contains($column, 'amount')) {
            return '$generator->randomFloat(' . $scale . ', 0, 1000)';
        }

        return '$generator->randomFloat(' . $scale . ', 0, ' . $max . ')';
    }

    private function guessText(string $column): string
    {
        if (str_contains($column, 'bio') || str_contains($column, 'description') || str_contains($column, 'content')) {
            return '$generator->realText(500)';
        }

        return '$generator->text(1000)';
    }

    /**
     * @return array<string>
     */
    private function collectForeignKeys(Table $table): array
    {
        $keys = [];
        foreach ($table->associations() as $association) {
            $key = $association->getForeignKey();
            if ($key === false) {
                continue;
            }
            $keys = array_merge($keys, (array)$key);
        }

        foreach ($table->getSchema()->constraints() as $constraintName) {
            $constraint = $table->getSchema()->getConstraint($constraintName);
            if ($constraint && $constraint['type'] === 'foreign') {
                $keys = array_merge($keys, $constraint['columns']);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string>
     */
    private function collectSingleColumnUniqueFields(Table $table): array
    {
        $schema = $table->getSchema();
        $uniqueFields = [];

        foreach ($schema->constraints() as $constraintName) {
            $constraint = $schema->getConstraint($constraintName);
            if ($constraint && $constraint['type'] === 'unique' && count($constraint['columns']) === 1) {
                $uniqueFields = array_merge($uniqueFields, $constraint['columns']);
            }
        }

        foreach ($schema->indexes() as $indexName) {
            $index = $schema->getIndex($indexName);
            if ($index && isset($index['type']) && $index['type'] === 'unique' && count($index['columns']) === 1) {
                $uniqueFields = array_merge($uniqueFields, $index['columns']);
            }
        }

        return array_values(array_unique($uniqueFields));
    }

    /**
     * Collect column names populated automatically on **insert** by Cake
     * behaviors attached to the table — i.e. fields the factory's NEW-row
     * save will leave the behavior to fill in. Today this only inspects
     * `TimestampBehavior` (`created` / `modified` by default, plus any
     * additional fields a project configures on `Model.beforeSave`).
     *
     * Conservative on purpose: only `Model.beforeSave` events count, and
     * only fields configured `'new'` or `'always'`. A field configured
     * `'existing'` (or wired to a non-`beforeSave` event) does NOT fire on
     * insert, so bake must still emit a value or the NOT NULL insert fails.
     *
     * @return array<string>
     */
    private function collectBehaviorManagedFields(Table $table): array
    {
        $fields = [];
        // Detect by CLASS, not alias: `addBehavior('AuditTimestamps',
        // ['className' => 'Timestamp'])` registers TimestampBehavior under a
        // custom alias, so `has('Timestamp')` is false. Iterate everything
        // loaded and instanceof-check. Multiple aliases of the behavior union.
        $behaviors = $table->behaviors();
        foreach ($behaviors->loaded() as $alias) {
            $behavior = $behaviors->get($alias);
            if (!$behavior instanceof TimestampBehavior) {
                continue;
            }
            $events = (array)$behavior->getConfig('events');
            foreach ($events as $eventName => $fieldsForEvent) {
                if ($eventName !== 'Model.beforeSave' || !is_array($fieldsForEvent)) {
                    continue;
                }
                foreach ($fieldsForEvent as $field => $when) {
                    if (
                        is_string($field)
                        && $field !== ''
                        && ($when === 'new' || $when === 'always')
                    ) {
                        $fields[$field] = true;
                    }
                }
            }
        }

        return array_keys($fields);
    }

    private function normalizeGeneratorExpression(string $generatorMethod): string
    {
        $generatorMethod = trim($generatorMethod);

        if ($generatorMethod === '') {
            return '';
        }
        if (str_starts_with($generatorMethod, '$generator->')) {
            return $generatorMethod;
        }
        if (!str_contains($generatorMethod, '(')) {
            return '$generator->' . $generatorMethod . '()';
        }

        return '$generator->' . $generatorMethod;
    }
}
