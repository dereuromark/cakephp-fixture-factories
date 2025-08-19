<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Command;

use Bake\Command\BakeCommand;
use Bake\Utility\TemplateRenderer;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\ORM\AssociationCollection;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use CakephpFixtureFactories\Factory\FactoryAwareTrait;
use Exception;
use ReflectionClass;

class BakeFixtureFactoryCommand extends BakeCommand
{
    use FactoryAwareTrait;

    /**
     * @var string path to the Table dir
     */
    public string $pathToTableDir = 'Model' . DS . 'Table' . DS;

    /**
     * @var string
     */
    private string $modelName;

    /**
     * @var \Cake\ORM\Table
     */
    private Table $table;

    /**
     * @var array
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
     * @return string Name of the command
     */
    public function name(): string
    {
        return 'fixture_factory';
    }

    /**
     * @return string Name of the template
     */
    public function template(): string
    {
        return 'fixture_factory';
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'bake fixture_factory';
    }

    /**
     * @return \Cake\ORM\Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * @param string $tableName Name of the table being baked
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return $this
     */
    public function setTable(string $tableName, ConsoleIo $io)
    {
        if ($this->plugin) {
            $tableName = $this->plugin . ".$tableName";
        }
        $this->table = TableRegistry::getTableLocator()->get($tableName);
        try {
            $this->table->getSchema();
        } catch (Exception $e) {
            /** @var string $modelPath */
            $modelPath = is_array($this->getModelPath()) ? implode(', ', $this->getModelPath()) : $this->getModelPath();
            $io->warning("The table $tableName could not be found... in " . $modelPath);
            $io->abort($e->getMessage());
        }

        return $this;
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     *
     * @return string
     */
    public function getPath(Arguments $args): string
    {
        $outputDir = Configure::read('FixtureFactories.testFixtureOutputDir', 'Factory/');
        $outputDir = rtrim($outputDir, DS) . '/';

        if ($this->plugin) {
            $path = $this->_pluginPath($this->plugin) . 'tests/' . $outputDir;
        } else {
            $path = TESTS . $outputDir;
        }

        return str_replace('/', DS, $path);
    }

    /**
     * Locate tables
     *
     * @return array<string>|string
     */
    public function getModelPath(): string|array
    {
        if (!empty($this->plugin)) {
            $path = $this->_pluginPath($this->plugin) . APP_DIR . DS . $this->pathToTableDir;
        } else {
            $path = APP . $this->pathToTableDir;
        }

        return str_replace('/', DS, $path);
    }

    /**
     * List the tables, ignore tables that should not be baked
     *
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return array
     */
    public function getTableList(ConsoleIo $io): array
    {
        /** @var string $modelPath */
        $modelPath = is_array($this->getModelPath()) ? $this->getModelPath()[0] : $this->getModelPath();
        $tables = glob($modelPath . '*Table.php') ?: [];

        $tables = array_map(function ($a) {
            $result = preg_replace('/Table.php$/', '', $a);

            return $result ?? $a;
        }, $tables);

        $return = [];
        foreach ($tables as $table) {
            /** @var string $modelPath */
            $modelPath = is_array($this->getModelPath()) ? $this->getModelPath()[0] : $this->getModelPath();
            $table = str_replace($modelPath, '', $table);
            if (!$this->thisTableShouldBeBaked($table, $io)) {
                $io->warning("{$table} ignored");
            } else {
                $return[] = $table;
            }
        }

        return $return;
    }

    /**
     * Return false if the table is not found or is abstract, interface or trait
     *
     * @param string $table Table
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return bool
     */
    public function thisTableShouldBeBaked(string $table, ConsoleIo $io): bool
    {
        /** @var class-string $tableClassName */
        $tableClassName = ($this->plugin ?: Configure::read('App.namespace'))
            . "\Model\Table\\{$table}Table";

        $class = new ReflectionClass($tableClassName);

        if ($class->isAbstract() || $class->isInterface() || $class->isTrait()) {
            return false;
        }

        return true;
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return string
     */
    private function bakeAllModels(Arguments $args, ConsoleIo $io): string
    {
        $tables = $this->getTableList($io);
        if (!$tables) {
            /** @var string $modelPath */
            $modelPath = is_array($this->getModelPath()) ? implode(', ', $this->getModelPath()) : $this->getModelPath();
            $io->err(sprintf('No tables were found at `%s`', $modelPath));
        } else {
            foreach ($tables as $table) {
                $this->bakeFixtureFactory($table, $args, $io);
            }
        }

        return '';
    }

    /**
     * Execute the command.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     *
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $this->extractCommonProperties($args);
        $model = $args->getArgument('model') ?? '';
        $model = $this->_getName($model);
        $loud = !$args->getOption('quiet');

        if ($this->plugin) {
            $parts = explode('/', $this->plugin);
            $this->plugin = implode('/', array_map([$this, '_camelize'], $parts));
            if (strpos($this->plugin, '\\')) {
                $io->out('Invalid plugin namespace separator, please use / instead of \ for plugins.');

                return self::CODE_SUCCESS;
            }
        }

        if ($args->getOption('all')) {
            $this->bakeAllModels($args, $io);

            return self::CODE_SUCCESS;
        }

        if (!$model) {
            if ($loud) {
                $io->out('Choose a table from the following, choose -a for all, or -h for help:');
            }
            foreach ($this->getTableList($io) as $table) {
                if ($loud) {
                    $io->out('- ' . $table);
                }
            }

            return self::CODE_SUCCESS;
        }

        $this->bakeFixtureFactory($model, $args, $io);

        return self::CODE_SUCCESS;
    }

    /**
     * @param string $modelName Name of the model
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return int|bool
     */
    public function bakeFixtureFactory(string $modelName, Arguments $args, ConsoleIo $io): bool|int
    {
        $this->modelName = $modelName;

        $this->setTable($modelName, $io);
        $renderer = new TemplateRenderer('CakephpFixtureFactories');
        $renderer->set($this->templateData($args));
        $renderer->viewBuilder()->addHelper('CakephpFixtureFactories.FactoryBake');

        $contents = $renderer->generate($this->template());

        $path = $this->getPath($args);
        $filename = $path . $this->getFactoryFileName($modelName);

        /** @var bool $forceOverwrite */
        $forceOverwrite = (bool)($args->getOption('force') ?? false);

        return $io->createFile($filename, $contents, $forceOverwrite);
    }

    /**
     * @inheritDoc
     */
    public function templateData(Arguments $arg): array
    {
        $rootTableRegistryName = $this->plugin ? $this->plugin . '.' . $this->modelName : $this->modelName;
        $entityClass = '\\' . TableRegistry::getTableLocator()->get($rootTableRegistryName)->getEntityClass();
        $data = [
            'rootTableRegistryName' => $rootTableRegistryName,
            'entityClass' => $entityClass,
            'modelNameSingular' => Inflector::singularize($this->modelName),
            'modelName' => $this->modelName,
            'factory' => Inflector::singularize($this->modelName) . 'Factory',
            'namespace' => $this->getFactoryNamespace($this->plugin),
            'defaultData' => $this->defaultData(),
        ];
        $useStatements = $methods = [];
        if ($arg->getOption('methods')) {
            $associations = $this->getAssociations();

            if ($associations['toOne']) {
                $data['toOne'] = $associations['toOne'];
                $useStatements[] = Hash::extract($associations['toOne'], '{s}.fqcn');
                $methods = array_keys($associations['toOne']);
            }

            if ($associations['oneToMany']) {
                $data['oneToMany'] = $associations['oneToMany'];
                $useStatements[] = Hash::extract($associations['oneToMany'], '{s}.fqcn');
                $methods = array_merge(array_keys($associations['oneToMany']), $methods);
            }

            if ($associations['manyToMany']) {
                $data['manyToMany'] = $associations['manyToMany'];
                $useStatements[] = Hash::extract($associations['manyToMany'], '{s}.fqcn');
                $methods = array_merge(array_keys($associations['manyToMany']), $methods);
            }

            array_walk($methods, function (&$value): void {
                $value = "with$value";
            });
            $data['methods'] = $methods;
            $data['useStatements'] = array_unique(array_values(Hash::flatten($useStatements)));
        }

        if (!empty($data['useStatements'])) {
            foreach ($data['useStatements'] as $index => $useStatement) {
                $nameSpaceCheck = str_replace($data['namespace'] . '\\', '', $useStatement);
                if (!str_contains($nameSpaceCheck, '\\')) {
                    unset($data['useStatements'][$index]);
                }
            }
        }

        return $data;
    }

    /**
     * Returns the one and many association for a given model
     *
     * @return array
     */
    public function getAssociations(): array
    {
        $associations = [
            'toOne' => [],
            'oneToMany' => [],
            'manyToMany' => [],
        ];

        foreach ($this->getTable()->associations() as $association) {
            $modelName = $association->getClassName();
            $factory = $this->getFactoryClassName($modelName);
            $factoryClassName = $this->getFactorySimpleClassName($modelName);
            switch ($association->type()) {
                case 'oneToOne':
                case 'manyToOne':
                    $associations['toOne'][$association->getName()] = [
                        'fqcn' => $factory,
                        'className' => $factoryClassName,
                    ];

                    break;
                case 'oneToMany':
                    $associations['oneToMany'][$association->getName()] = [
                        'fqcn' => $factory,
                        'className' => $factoryClassName,
                    ];

                    break;
                case 'manyToMany':
                    $associations['manyToMany'][$association->getName()] = [
                        'fqcn' => $factory,
                        'className' => $factoryClassName,
                    ];

                    break;
            }
        }

        return $associations;
    }

    /**
     * @param string $name Name of the factory
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return void
     */
    public function handleFactoryWithSameName(string $name, Arguments $args, ConsoleIo $io): void
    {
        $factoryWithSameName = glob($this->getPath($args) . $name . '.php');
        if ($factoryWithSameName) {
            if (!$args->getOption('force')) {
                $io->abort(
                    sprintf(
                        'A factory with the name `%s` already exists.',
                        $name,
                    ),
                );
            }

            $io->info(sprintf('A factory with the name `%s` already exists, it will be deleted.', $name));
            foreach ($factoryWithSameName as $factory) {
                $io->info(sprintf('Deleting factory file `%s`...', $factory));
                if (unlink($factory)) {
                    $io->success(sprintf('Deleted `%s`', $factory));
                } else {
                    $io->err(sprintf('An error occurred while deleting `%s`', $factory));
                }
            }
        }
    }

    /**
     * Gets the option parser instance and configures it.
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser(): ConsoleOptionParser
    {
        $name = ($this->plugin ? $this->plugin . '.' : '') . $this->name;
        $parser = new ConsoleOptionParser($name);

        $parser = $this->_setCommonOptions($parser);

        $parser->setDescription(
            'Fixture factory generator.',
        )
            ->addArgument('model', [
                'help' => 'Name of the model the factory will create entities from'
                    . '(plural, without the `Table` suffix). You can use the Foo.Bars notation '
                    . 'to bake a factory for the model Bars located in the plugin Foo. \n
                    Factories are located in the folder test\Factory of your app, resp. plugin.',
            ])
            ->addOption('all', [
                'short' => 'a',
                'boolean' => true,
                'help' => 'Bake factories for all models.',
            ])
            ->addOption('methods', [
                'short' => 'm',
                'boolean' => true,
                'help' => 'Include methods based on the table relations.',
            ]);

        return $parser;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultData(): array
    {
        $defaultData = [];

        $modelName = $this->getTable()->getAlias();
        $schema = $this->getTable()->getSchema();
        $columns = $schema->columns();
        $foreignKeys = $this->foreignKeys($this->getTable()->associations());
        foreach ($columns as $column) {
            $keys = $schema->getPrimaryKey();
            if (in_array($column, $keys, true) || in_array($column, $foreignKeys, true)) {
                continue;
            }

            $columnSchema = $schema->getColumn($column);
            if ($columnSchema['null'] || $columnSchema['default'] !== null) {
                continue;
            }

            if (!in_array($columnSchema['type'], ['integer', 'string', 'date', 'datetime', 'time', 'boolean', 'float', 'decimal', 'uuid', 'json', 'text'])) {
                continue;
            }

            $guessedDefault = $this->guessDefault($column, $modelName, $columnSchema);
            if ($guessedDefault) {
                $defaultData[$column] = $guessedDefault;
            }
        }

        return $defaultData;
    }

    /**
     * @param string $column
     * @param string $modelName
     * @param array $columnSchema
     *
     * @return mixed
     */
    protected function guessDefault(string $column, string $modelName, array $columnSchema): mixed
    {
        // Merge default mappings with custom configuration
        $map = array_merge_recursive($this->map, (array)Configure::read('FixtureFactories.defaultDataMap'));

        // Check custom column patterns from configuration first
        $customPatterns = Configure::read('FixtureFactories.columnPatterns', []);
        foreach ($customPatterns as $pattern => $generatorMethod) {
            if (preg_match($pattern, $column)) {
                return '$generator->' . $generatorMethod;
            }
        }

        // Pattern-based detection for special cases
        if (str_ends_with($column, '_at') && $columnSchema['type'] === 'datetime') {
            return '$generator->optional(0.7)->dateTime()';
        }

        if (str_ends_with($column, '_count') && $columnSchema['type'] === 'integer') {
            return '$generator->numberBetween(0, 100)';
        }

        $map = $map[$columnSchema['type']] ?? [];

        $modelNameMap = [
            'Countries' => 'country',
            'Cities' => 'city',
        ];

        // Handle string type with improved logic
        if ($columnSchema['type'] === 'string') {
            if ($column === 'name' && isset($modelNameMap[$modelName])) {
                return '$generator->' . $modelNameMap[$modelName] . '()';
            }
            if (isset($map[$column])) {
                return '$generator->' . $map[$column] . '()';
            }

            // Smart string length handling
            $length = $columnSchema['length'] ?? 255;
            if ($length <= 3) {
                return '$generator->lexify("' . str_repeat('?', $length) . '")';
            } elseif ($length <= 10) {
                return '$generator->word()';
            } elseif ($length <= 50) {
                return 'implode(" ", $generator->words(3))';
            } elseif ($length <= 100) {
                return '$generator->sentence()';
            }

            return '$generator->text(' . $length . ')';
        }

        // Handle integer type
        if ($columnSchema['type'] === 'integer') {
            if (isset($map[$column])) {
                return '$generator->' . $map[$column] . '()';
            }

            return '$generator->randomNumber()';
        }

        // Handle boolean/bool type
        if ($columnSchema['type'] === 'boolean' || $columnSchema['type'] === 'bool') {
            if (isset($map[$column])) {
                return '$generator->' . $map[$column] . '()';
            }

            return '$generator->boolean()';
        }

        // Handle float type with enhanced detection
        if ($columnSchema['type'] === 'float') {
            if (isset($map[$column])) {
                return '$generator->' . $map[$column];
            }

            // Default float generation
            return '$generator->randomFloat(2, 0, 100)';
        }

        // Handle decimal type (new)
        if ($columnSchema['type'] === 'decimal') {
            $precision = $columnSchema['precision'] ?? 10;
            $scale = $columnSchema['scale'] ?? 2;
            $max = (string)(pow(10, (int)$precision - (int)$scale) - 1);

            if (str_contains($column, 'price') || str_contains($column, 'cost') || str_contains($column, 'amount')) {
                return '$generator->randomFloat(' . $scale . ', 0, 1000)';
            }

            return '$generator->randomFloat(' . $scale . ', 0, ' . $max . ')';
        }

        // Handle uuid type (new)
        if ($columnSchema['type'] === 'uuid') {
            return '$generator->uuid()';
        }

        // Handle json type (new)
        if ($columnSchema['type'] === 'json') {
            return 'json_encode(["key" => $generator->word(), "value" => $generator->randomNumber()])';
        }

        // Handle text type (new) - different from string
        if ($columnSchema['type'] === 'text') {
            if (str_contains($column, 'bio') || str_contains($column, 'description') || str_contains($column, 'content')) {
                return '$generator->realText(500)';
            }

            return '$generator->text(1000)';
        }

        // Handle date type
        if ($columnSchema['type'] === 'date') {
            if (isset($map[$column])) {
                return '$generator->' . $map[$column] . '()';
            }

            return '$generator->date()';
        }

        // Handle datetime type
        if ($columnSchema['type'] === 'datetime') {
            if (isset($map[$column])) {
                return '$generator->' . $map[$column] . '()';
            }

            return '$generator->datetime()';
        }

        // Handle time type
        if ($columnSchema['type'] === 'time') {
            if (isset($map[$column])) {
                return '$generator->' . $map[$column] . '()';
            }

            return '$generator->time()';
        }

        return null;
    }

    /**
     * @param \Cake\ORM\AssociationCollection $associations
     *
     * @return array<string>
     */
    protected function foreignKeys(AssociationCollection $associations): array
    {
        $keys = [];

        foreach ($associations as $association) {
            $key = $association->getForeignKey();
            if ($key === false) {
                continue;
            }
            $keys = array_merge($keys, (array)$key);
        }

        return $keys;
    }
}
