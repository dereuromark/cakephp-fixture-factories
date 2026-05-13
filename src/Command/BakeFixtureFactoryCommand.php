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
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use CakephpFixtureFactories\Codegen\DefaultDataGuesser;
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
     * Guesser for the body of the baked `definition()` method. Override in
     * a subclass to swap in a project-specific guesser (e.g. one that knows
     * about your domain conventions).
     *
     * @var \CakephpFixtureFactories\Codegen\DefaultDataGuesser|null
     */
    protected ?DefaultDataGuesser $defaultDataGuesser = null;

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
            $io->warning("The table $tableName could not be found... in " . $this->getModelPathString());
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
        if ($this->plugin) {
            $path = $this->_pluginPath($this->plugin) . APP_DIR . DS . $this->pathToTableDir;
        } else {
            $path = APP . $this->pathToTableDir;
        }

        return str_replace('/', DS, $path);
    }

    /**
     * Get the model path as a string for display purposes.
     *
     * @return string
     */
    protected function getModelPathString(): string
    {
        $modelPath = $this->getModelPath();

        return is_array($modelPath) ? implode(', ', $modelPath) : $modelPath;
    }

    /**
     * List the tables, ignore tables that should not be baked
     *
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return array<string>
     */
    public function getTableList(ConsoleIo $io): array
    {
        $modelPath = $this->getModelPathString();
        $tables = glob($modelPath . '*Table.php') ?: [];

        $tables = array_map(
            static fn (string $a): string => preg_replace('/Table.php$/', '', $a) ?? $a,
            $tables,
        );

        $return = [];
        foreach ($tables as $table) {
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

        if (!class_exists($tableClassName)) {
            return false;
        }

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
     * @return int Exit code: CODE_SUCCESS unless no tables were found.
     */
    private function bakeAllModels(Arguments $args, ConsoleIo $io): int
    {
        $tables = $this->getTableList($io);
        if (!$tables) {
            $io->err(sprintf('No tables were found at `%s`', $this->getModelPathString()));

            return self::CODE_ERROR;
        }
        foreach ($tables as $table) {
            $this->bakeFixtureFactory($table, $args, $io);
        }

        return self::CODE_SUCCESS;
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
        // Parent extractCommonProperties() already camelizes parts and throws
        // InvalidArgumentException on backslash separators (i.e. `Foo\Bar`),
        // so no extra plugin parsing is needed here.
        $this->extractCommonProperties($args);
        $model = $args->getArgument('model') ?? '';
        $model = $this->_getName($model);
        $loud = !$args->getOption('quiet');

        if ($args->getOption('all')) {
            return $this->bakeAllModels($args, $io);
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
     * @param \Cake\Console\Arguments $arg Arguments
     *
     * @return array<string, mixed>
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
            'defaultData' => $this->defaultData($arg),
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

            $data['methods'] = array_map(static fn (string $value): string => "with$value", $methods);
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
     * @return array<string, array<string, array<string, string>>>
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
                    . ' (plural, without the `Table` suffix). You can use the Foo.Bars notation'
                    . ' to bake a factory for the model Bars located in the plugin Foo.'
                    . ' Factories are located in the folder tests/Factory of your app, resp. plugin.',
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
            ])
            ->addOption('all-fields', [
                'boolean' => true,
                'help' => 'Emit default values for nullable columns and columns with DB defaults too, '
                    . 'instead of only required NOT NULL columns. Foreign keys are still excluded '
                    . 'so the baked factory keeps pushing related rows through with()/for()/has().',
            ]);

        return $parser;
    }

    /**
     * Build the `column => generator-expression` map fed to the bake template.
     *
     * Honours the `--all-fields` option when available — emits defaults for
     * nullable / DB-defaulted columns too (foreign keys remain excluded).
     *
     * @param \Cake\Console\Arguments|null $args Bake-command arguments. Optional
     *     for subclasses / callers that want the legacy required-only behavior.
     *
     * @return array<string, string>
     */
    protected function defaultData(?Arguments $args = null): array
    {
        $guesser = $this->getDefaultDataGuesser();
        // Always set both directions: the guesser may be cached across multiple
        // executions on the same command instance, and a prior --all-fields run
        // must not leak into a subsequent invocation without the flag.
        $guesser->setIncludeOptional($args !== null && (bool)$args->getOption('all-fields'));

        return $guesser->guessFor($this->getTable());
    }

    /**
     * Lazily instantiates a {@see DefaultDataGuesser}. Override or set the
     * `$defaultDataGuesser` property in a subclass to swap in a custom
     * guesser without touching this command.
     */
    protected function getDefaultDataGuesser(): DefaultDataGuesser
    {
        if ($this->defaultDataGuesser === null) {
            $this->defaultDataGuesser = new DefaultDataGuesser();
        }

        return $this->defaultDataGuesser;
    }
}
