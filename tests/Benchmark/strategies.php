<?php

declare(strict_types=1);

/**
 * Compares the cleanup strategies on a generated schema.
 *
 * Usage:
 *   php tests/Benchmark/strategies.php <dsn> [--tests=200] [--rounds=3] [--groups=20]
 *
 * Examples:
 *   php tests/Benchmark/strategies.php "sqlite:////tmp/ff-bench.sqlite"
 *   php tests/Benchmark/strategies.php "mysql://root:root@127.0.0.1:3306/ff_bench"
 *   php tests/Benchmark/strategies.php "postgres://postgres:root@127.0.0.1:5432/ff_bench"
 *
 * Each group adds three FK-linked tables (parents, children, items), so the default is 60 tables.
 * A simulated test inserts ~30 rows into three random groups and counts them back.
 */

use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\Fixture\FixtureStrategyInterface;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use CakephpFixtureFactories\TestSuite\LazyFactoryTransactionStrategy;
use CakephpFixtureFactories\TestSuite\Sniffer\SnifferRegistry;
use CakephpFixtureFactories\TestSuite\TriggerStrategy;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dsn = $argv[1] ?? null;
if (!$dsn) {
    fwrite(STDERR, "Usage: php tests/Benchmark/strategies.php <dsn> [--tests=200] [--rounds=3] [--groups=20]\n");
    exit(1);
}
$options = ['tests' => 200, 'rounds' => 3, 'groups' => 20];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--(tests|rounds|groups)=(\d+)$/', $arg, $m)) {
        $options[$m[1]] = (int)$m[2];
    }
}

define('ROOT', dirname(__DIR__, 2));
define('TMP', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
define('CACHE', TMP);

ConnectionManager::setConfig('test', ['url' => $dsn, 'cacheMetadata' => false, 'quoteIdentifiers' => true]);
ConnectionManager::alias('test', 'default');

/** @var \Cake\Database\Connection $connection */
$connection = ConnectionManager::get('test');
$driver = $connection->getDriver();

$tables = [];
for ($g = 1; $g <= $options['groups']; $g++) {
    $tables[] = "g{$g}_parents";
    $tables[] = "g{$g}_children";
    $tables[] = "g{$g}_items";
    foreach (['Parents' => 'parents', 'Children' => 'children', 'Items' => 'items'] as $suffix => $kind) {
        $definition = match ($kind) {
            'parents' => "['name' => \$generator->name(), 'email' => \$generator->email(), 'status' => 'active', 'score' => \$generator->numberBetween(0, 1000)]",
            'children' => "['title' => \$generator->sentence(), 'body' => \$generator->text(), 'amount' => \$generator->randomFloat(2, 0, 9999)]",
            'items' => "['sku' => \$generator->uuid(), 'qty' => \$generator->numberBetween(1, 50), 'price' => \$generator->randomFloat(2, 1, 500), 'note' => \$generator->words(3, true)]",
        };
        eval(<<<PHP
            class G{$g}{$suffix}Factory extends \CakephpFixtureFactories\Factory\BaseFactory
            {
                protected function getRootTableRegistryName(): string
                {
                    return 'G{$g}{$suffix}';
                }

                public function definition(\CakephpFixtureFactories\Generator\GeneratorInterface \$generator): array
                {
                    return {$definition};
                }
            }
            PHP);
    }
}

function createSchema(Connection $connection, array $tables, int $groups): void
{
    $driver = $connection->getDriver();
    $pk = match (true) {
        $driver instanceof Sqlite => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
        $driver instanceof Postgres => 'id SERIAL PRIMARY KEY',
        default => 'id INT AUTO_INCREMENT PRIMARY KEY',
    };
    $ts = $driver instanceof Postgres ? 'TIMESTAMP' : 'DATETIME';

    dropSchema($connection, $tables);
    for ($g = 1; $g <= $groups; $g++) {
        $connection->execute("CREATE TABLE g{$g}_parents ({$pk}, name VARCHAR(255), email VARCHAR(255), status VARCHAR(20), score INT, created {$ts} NULL, modified {$ts} NULL)");
        $connection->execute("CREATE INDEX g{$g}_parents_email ON g{$g}_parents (email)");
        $connection->execute("CREATE TABLE g{$g}_children ({$pk}, parent_id INT NOT NULL REFERENCES g{$g}_parents (id), title VARCHAR(255), body TEXT, amount DECIMAL(10,2), created {$ts} NULL)");
        $connection->execute("CREATE INDEX g{$g}_children_parent ON g{$g}_children (parent_id)");
        $connection->execute("CREATE TABLE g{$g}_items ({$pk}, child_id INT NOT NULL REFERENCES g{$g}_children (id), sku VARCHAR(64), qty INT, price DECIMAL(10,2), note VARCHAR(255))");
        $connection->execute("CREATE INDEX g{$g}_items_child ON g{$g}_items (child_id)");
    }
}

function dropSchema(Connection $connection, array $tables): void
{
    if (SnifferRegistry::exists('test')) {
        SnifferRegistry::clear();
    }
    $connection->disableConstraints(function (Connection $connection) use ($tables): void {
        $cascade = $connection->getDriver() instanceof Postgres ? ' CASCADE' : '';
        foreach (array_reverse($tables) as $table) {
            $connection->execute("DROP TABLE IF EXISTS {$table}{$cascade}");
        }
    });
}

/**
 * Truncates every table before each test, the cost the trigger approach is meant to avoid.
 */
final class TruncateAllStrategy implements FixtureStrategyInterface
{
    public function __construct(private Connection $connection, private array $tables)
    {
    }

    public function setupTest(array $fixtureNames): void
    {
        $driver = $this->connection->getDriver();
        if ($driver instanceof Postgres) {
            $this->connection->execute('TRUNCATE ' . implode(', ', $this->tables) . ' RESTART IDENTITY CASCADE');

            return;
        }
        $this->connection->disableConstraints(function (Connection $connection) use ($driver): void {
            foreach ($this->tables as $table) {
                $connection->execute($driver instanceof Sqlite ? "DELETE FROM {$table}" : "TRUNCATE TABLE {$table}");
            }
        });
    }

    public function teardownTest(): void
    {
    }
}

function runTest(int $seed, int $groups): void
{
    mt_srand($seed);
    $picked = array_rand(array_flip(range(1, $groups)), 3);
    foreach ($picked as $g) {
        $parent = ("G{$g}ParentsFactory")::new()->save();
        $children = ("G{$g}ChildrenFactory")::new(['parent_id' => $parent->id], 3)->saveMany();
        foreach (array_slice($children, 0, 2) as $child) {
            ("G{$g}ItemsFactory")::new(['child_id' => $child->id], 3)->saveMany();
        }
        foreach (['Parents', 'Children', 'Items'] as $suffix) {
            TableRegistry::getTableLocator()->get("G{$g}{$suffix}")->find()->count();
        }
    }
}

$strategies = [
    'transaction' => fn () => new FactoryTransactionStrategy(),
    'lazy-transaction' => fn () => new LazyFactoryTransactionStrategy(),
    'trigger' => fn () => new TriggerStrategy(),
    'truncate-all' => fn () => new TruncateAllStrategy($connection, $tables),
];

$results = [];
for ($round = 1; $round <= $options['rounds']; $round++) {
    $order = array_keys($strategies);
    if ($round % 2 === 0) {
        $order = array_reverse($order);
    }
    foreach ($order as $name) {
        createSchema($connection, $tables, $options['groups']);
        TableRegistry::getTableLocator()->clear();
        $strategy = $strategies[$name]();

        $start = hrtime(true);
        $strategy->setupTest([]);
        runTest(0, $options['groups']);
        $strategy->teardownTest();
        $first = hrtime(true) - $start;

        $start = hrtime(true);
        for ($i = 1; $i < $options['tests']; $i++) {
            $strategy->setupTest([]);
            runTest($i, $options['groups']);
            $strategy->teardownTest();
        }
        $rest = hrtime(true) - $start;

        $results[$name][] = ['first' => $first / 1e6, 'steady' => $rest / 1e6 / ($options['tests'] - 1)];
        fprintf(STDERR, "round %d %-16s first %8.1f ms  per test %6.2f ms\n", $round, $name, $first / 1e6, $rest / 1e6 / ($options['tests'] - 1));
    }
}
dropSchema($connection, $tables);

$median = function (array $values): float {
    sort($values);
    $n = count($values);

    return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
};

$label = (new ReflectionClass($driver))->getShortName();
printf("\n%s, %d tables, %d tests x %d rounds, load %s\n\n", $label, count($tables), $options['tests'], $options['rounds'], trim((string)file_get_contents('/proc/loadavg')));
printf("| %-16s | %14s | %16s | %14s |\n", 'strategy', 'first test ms', 'per test ms', 'suite total s');
printf("|%s|%s|%s|%s|\n", str_repeat('-', 18), str_repeat('-', 16), str_repeat('-', 18), str_repeat('-', 16));
foreach ($results as $name => $runs) {
    $first = $median(array_column($runs, 'first'));
    $steady = $median(array_column($runs, 'steady'));
    printf("| %-16s | %14.1f | %16.2f | %14.2f |\n", $name, $first, $steady, ($first + $steady * ($options['tests'] - 1)) / 1000);
}
