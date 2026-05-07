<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.3
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use CakephpFixtureFactories\Error\FactoryNotFoundException;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Factory\FactoryAwareTrait;
use InvalidArgumentException;
use ReflectionException;
use ReflectionMethod;

class PersistCommand extends Command
{
    use FactoryAwareTrait;

    /**
     * @var string
     */
    public const ARG_NAME = 'model';

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'fixture_factories persist';
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Helper to persist test fixtures on the command line')
            ->addArgument(self::ARG_NAME, [
                'help' => 'The model to persist, accepts plugin notation. Or provide a fully qualified factory class.',
                'required' => true,
            ])
            ->addOption('plugin', [
                'help' => 'Fetch the factory in a plugin.',
                'short' => 'p',
            ])
            ->addOption('connection', [
                'help' => 'Persist into this connection.',
                'short' => 'c',
                'default' => 'test',
            ])
            ->addOption('method', [
                'help' => 'Call this method defined in the factory class concerned.',
                'short' => 'm',
            ])
            ->addOption('number', [
                'help' => 'Number of entities to persist.',
                'short' => 'n',
                'default' => '1',
            ])
            ->addOption('with', [
                'help' => 'With associated entity/entities.',
                'short' => 'w',
            ])
            ->addOption('dry-run', [
                'help' => 'Display the entities created without persisting.',
                'short' => 'd',
                'boolean' => true,
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $factory = null;
        try {
            $factory = $this->parseFactory($args);
            // The following order is important, as methods may overwrite $times
            $factory = $this->countFactory($args, $factory);
            $factory = $this->with($args, $factory);
            $factory = $this->attachMethod($args, $factory, $io);
        } catch (FactoryNotFoundException | InvalidArgumentException $e) {
            $io->error($e->getMessage());
            $this->abort();
        }
        if ($args->getOption('dry-run')) {
            $this->dryRun($factory, $args, $io);
        } else {
            $this->persist($factory, $args, $io);
        }

        return self::CODE_SUCCESS;
    }

    /**
     * @param \Cake\Console\Arguments $args The command arguments
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    protected function parseFactory(Arguments $args): BaseFactory
    {
        $factoryString = (string)$args->getArgument(self::ARG_NAME);

        if (is_subclass_of($factoryString, BaseFactory::class)) {
            return $factoryString::new();
        }

        $plugin = $args->getOption('plugin');
        if (is_string($plugin)) {
            $factoryString = $plugin . '.' . $factoryString;
        }

        return $this->getFactory($factoryString);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     *
     * @throws \InvalidArgumentException When --number is not a positive integer.
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    protected function countFactory(Arguments $args, BaseFactory $factory): BaseFactory
    {
        $option = $args->getOption('number');
        if ($option === null) {
            return $factory->count(1);
        }

        if (is_string($option) && ctype_digit($option)) {
            $times = (int)$option;
        } else {
            throw new InvalidArgumentException(sprintf(
                '--number must be a positive integer, got `%s`.',
                (string)$option,
            ));
        }

        if ($times < 1) {
            throw new InvalidArgumentException(sprintf(
                '--number must be a positive integer, got `%s`.',
                (string)$option,
            ));
        }

        return $factory->count($times);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @throws \CakephpFixtureFactories\Error\FactoryNotFoundException if the method is not found in the factory
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    protected function attachMethod(Arguments $args, BaseFactory $factory, ConsoleIo $io): BaseFactory
    {
        /** @var string|null $method */
        $method = $args->getOption('method');

        if ($method === null) {
            return $factory;
        }

        try {
            $reflectionMethod = new ReflectionMethod($factory, $method);
        } catch (ReflectionException) {
            $className = get_class($factory);
            $io->error("The method {$method} was not found in {$className}.");

            throw new FactoryNotFoundException();
        }

        if (!$reflectionMethod->isPublic()) {
            throw new InvalidArgumentException(sprintf(
                'The method `%s` on `%s` must be public to be called from persist command.',
                $method,
                get_class($factory),
            ));
        }
        if ($reflectionMethod->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException(sprintf(
                'The method `%s` on `%s` must not require arguments when called from persist command.',
                $method,
                get_class($factory),
            ));
        }

        $result = $factory->{$method}();
        if (!$result instanceof BaseFactory) {
            throw new InvalidArgumentException(sprintf(
                'The method `%s` on `%s` must return a BaseFactory when called from persist command.',
                $method,
                get_class($factory),
            ));
        }

        return $result;
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface>
     */
    protected function with(Arguments $args, BaseFactory $factory): BaseFactory
    {
        $with = $args->getOption('with');

        if ($with === null) {
            return $factory;
        }

        if (!is_string($with)) {
            return $factory;
        }

        return $factory->with($with);
    }

    /**
     * Sets the connection passed in argument as the target connection,
     * overwriting the table's default connection.
     *
     * @param string $connection Connection name
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     *
     * @return callable Restore callback
     */
    protected function aliasConnection(string $connection, BaseFactory $factory): callable
    {
        $targetAlias = $factory->getTable()->getConnection()->configName();
        $existingAliases = ConnectionManager::aliases();
        $previousSource = $existingAliases[$targetAlias] ?? null;

        ConnectionManager::alias($connection, $targetAlias);

        return static function () use ($targetAlias, $previousSource): void {
            ConnectionManager::dropAlias($targetAlias);
            if ($previousSource !== null) {
                ConnectionManager::alias($previousSource, $targetAlias);
            }
        };
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return void
     */
    protected function persist(BaseFactory $factory, Arguments $args, ConsoleIo $io): void
    {
        $connection = $args->getOption('connection') ?? 'test';
        if (!is_string($connection)) {
            $connection = 'test';
        }
        $restoreConnectionAlias = $this->aliasConnection($connection, $factory);

        $entities = [];
        try {
            $entities = $factory->saveMany();
        } catch (PersistenceException $e) {
            $io->error($e->getMessage());
            $this->abort();
        } finally {
            $restoreConnectionAlias();
        }

        $times = count($entities);
        $factory = get_class($factory);
        $io->success("{$times} {$factory} persisted on '{$connection}' connection.");
    }

    /**
     * @param \CakephpFixtureFactories\Factory\BaseFactory<\Cake\Datasource\EntityInterface> $factory Factory
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console
     *
     * @return void
     */
    protected function dryRun(BaseFactory $factory, Arguments $args, ConsoleIo $io): void
    {
        $connection = $args->getOption('connection') ?? 'test';
        if (!is_string($connection)) {
            $connection = 'test';
        }
        $restoreConnectionAlias = $this->aliasConnection($connection, $factory);

        try {
            $entities = $factory->buildMany();
        } finally {
            $restoreConnectionAlias();
        }
        $times = count($entities);
        $factory = get_class($factory);

        $io->success("{$times} {$factory} generated on dry run.");
        foreach ($entities as $i => $entity) {
            $io->hr();
            $io->info("[$i]");
            $output = json_encode($entity->toArray(), JSON_PRETTY_PRINT);
            if ($output !== false) {
                $io->info($output);
            }
        }
    }
}
