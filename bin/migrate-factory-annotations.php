#!/usr/bin/env php
<?php
/**
 * One-shot migration script for the 1.3 to 1.4 upgrade.
 *
 * Replaces hand-rolled method annotation blocks on Factory subclasses
 * (the three lines covering getEntity, getEntities, persist) with the
 * canonical generic-extends form
 *
 *   extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\X>
 *
 * Idempotent: running it twice is a no-op. Files that already use the
 * extends form, or do not match the legacy pattern, are left untouched.
 *
 * Usage:
 *
 *   php bin/migrate-factory-annotations.php tests/Factory [more/paths ...]
 *   php bin/migrate-factory-annotations.php --dry-run tests/Factory
 *
 * Each path can be a directory (scanned recursively for *.php) or a single
 * file. Multiple paths are processed in order.
 */

declare(strict_types=1);

use Cake\Utility\Inflector;
use CakephpFixtureFactories\Factory\BaseFactory;

// Best-effort: load the project's autoloader so we can verify candidate
// entity classes exist. Without it class_exists() always returns false
// and the script will conservatively pick \Cake\Datasource\EntityInterface
// rather than a wrong guess.
$autoload = getcwd() . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$argv = $_SERVER['argv'] ?? [];
array_shift($argv);

$dryRun = false;
$paths = [];
foreach ($argv as $arg) {
    if ($arg === '--dry-run' || $arg === '-d') {
        $dryRun = true;

        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        usage();
        exit(0);
    }
    $paths[] = $arg;
}

if (!$paths) {
    usage();
    exit(64);
}

$replaced = 0;
$skippedAlreadyMigrated = 0;
$skippedNoMatch = 0;
$scanned = 0;

foreach ($paths as $path) {
    foreach (collectFiles($path) as $file) {
        $scanned++;
        $src = (string)file_get_contents($file);
        if (!extendsBaseFactory($src)) {
            $skippedNoMatch++;

            continue;
        }
        if (preg_match('/@extends\h+\\\\?CakephpFixtureFactories\\\\Factory\\\\BaseFactory</', $src)) {
            $skippedAlreadyMigrated++;

            continue;
        }
        $entity = deriveEntityFqn($src, $file);
        if ($entity === null) {
            $skippedNoMatch++;

            continue;
        }
        $replacementLine = sprintf(' * @extends \\CakephpFixtureFactories\\Factory\\BaseFactory<%s>', $entity);
        $new = rewriteClassDocblock($src, $replacementLine);
        if ($new === null) {
            $skippedNoMatch++;

            continue;
        }
        if ($new === $src) {
            $skippedAlreadyMigrated++;

            continue;
        }
        if (!$dryRun) {
            file_put_contents($file, $new);
        }
        $replaced++;
        printf("%s %s\n", $dryRun ? '[dry-run]' : '[migrated]', $file);
    }
}

printf(
    "\nScanned: %d, migrated: %d, already migrated: %d, skipped (not a factory or no match): %d%s\n",
    $scanned,
    $replaced,
    $skippedAlreadyMigrated,
    $skippedNoMatch,
    $dryRun ? ' (dry-run, no files were written)' : '',
);

exit($replaced > 0 || $scanned > 0 ? 0 : 1);

/**
 * Collect *.php files under a path. Path may be a single file or a directory.
 *
 * @return iterable<string>
 */
function collectFiles(string $path): iterable
{
    if (is_file($path)) {
        if (str_ends_with($path, '.php')) {
            yield $path;
        }

        return;
    }
    if (!is_dir($path)) {
        fwrite(STDERR, "warning: path not found: {$path}\n");

        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            yield $file->getPathname();
        }
    }
}

/**
 * Derive the entity FQN for a factory.
 *
 * Strategy:
 *   1. If the factory's getRootTableRegistryName() returns a "Plugin.Table"
 *      string, derive \Plugin\Model\Entity\<Singular(Table)>. This handles
 *      cross-plugin factories like an App\Test\Factory\CommentFactory that
 *      targets the Comments plugin.
 *   2. Otherwise (or if the candidate class does not exist), derive from
 *      the factory's own namespace:
 *        App\Test\Factory\InvoiceFactory -> \App\Model\Entity\Invoice
 *        MyPlugin\Test\Factory\ThingFactory -> \MyPlugin\Model\Entity\Thing
 *   3. If the namespace-based candidate doesn't exist either, fall back to
 *      \Cake\Datasource\EntityInterface — a safe, always-loadable default
 *      that keeps the docblock honest rather than pointing at a phantom
 *      class.
 *
 * The class-exists checks only fire when the project's vendor/autoload.php
 * was loadable from cwd. Without it the script keeps the old behaviour
 * of trusting the namespace convention.
 */
function deriveEntityFqn(string $src, string $file): ?string
{
    if (!preg_match('/^namespace\h+([\w\\\\]+);/m', $src, $nsMatch)) {
        return null;
    }
    if (!preg_match('/^class\h+(\w+)Factory\b/m', $src, $classMatch)) {
        return null;
    }
    $namespace = $nsMatch[1];
    $entityName = $classMatch[1];

    // Namespace-based default (the original heuristic).
    $entityNs = preg_replace('/\\\\Test\\\\Factory$/', '\\Model\\Entity', $namespace);
    if ($entityNs === $namespace) {
        // Namespace did not end with \Test\Factory — fall back to a best-effort guess.
        $entityNs = $namespace . '\\Model\\Entity';
    }
    $namespaceCandidate = '\\' . $entityNs . '\\' . $entityName;

    // No plugin hint -> stick with the namespace convention. Verifying via
    // class_exists() here would break factories whose entities live outside
    // the autoload path the script can see (e.g. tests that fixture a
    // factory inline).
    $pluginCandidate = derivePluginEntityFqn($src);
    if ($pluginCandidate === null) {
        return $namespaceCandidate;
    }

    // Plugin hint present: prefer the plugin's entity when it actually exists.
    if (entityClassExists($pluginCandidate)) {
        return $pluginCandidate;
    }

    // Plugin candidate didn't autoload. Try the namespace candidate next.
    if (entityClassExists($namespaceCandidate)) {
        return $namespaceCandidate;
    }

    // Without an autoloader the class_exists checks above are uninformative;
    // keep the more-specific plugin candidate as a best guess.
    if (!autoloadAvailable()) {
        return $pluginCandidate;
    }

    // Autoloader said no class exists at either spot — bail to a safe
    // interface rather than emitting a phantom FQN.
    return '\\Cake\\Datasource\\EntityInterface';
}

/**
 * Read getRootTableRegistryName() from the source and turn "Plugin.Table"
 * into \Plugin\Model\Entity\<Singular(Table)>. Returns null when the method
 * isn't present, doesn't return a literal string, or the name has no plugin
 * prefix.
 */
function derivePluginEntityFqn(string $src): ?string
{
    // Allow whitespace, // line comments, and /* block comments */ between
    // the opening brace and the return statement so that we still recognise
    // factories that explain the registry choice with a leading comment.
    $separator = '(?:\s|\/\/[^\n]*\n|\/\*.*?\*\/)*';
    if (
        !preg_match(
            '/function\s+getRootTableRegistryName\s*\([^)]*\)\s*:\s*string\s*\{' . $separator . 'return\s*[\'"]([^\'"]+)[\'"]/s',
            $src,
            $m,
        )
    ) {
        return null;
    }
    $registryName = $m[1];
    if (!str_contains($registryName, '.')) {
        return null;
    }
    [$plugin, $table] = explode('.', $registryName, 2);
    $pluginNs = str_replace('/', '\\', $plugin);
    $singular = singularize($table);

    return '\\' . $pluginNs . '\\Model\\Entity\\' . $singular;
}

function entityClassExists(string $fqn): bool
{
    $name = ltrim($fqn, '\\');

    return class_exists($name) || interface_exists($name);
}

function autoloadAvailable(): bool
{
    // BaseFactory ships with the plugin and is always available once the
    // project autoloader has been required. Use it as a marker.
    return class_exists(BaseFactory::class);
}

function singularize(string $word): string
{
    if (class_exists(Inflector::class)) {
        return Inflector::singularize($word);
    }
    // Minimal fallback singularizer for the standalone case.
    if (preg_match('/(.+)ies$/', $word, $m)) {
        return $m[1] . 'y';
    }
    if (preg_match('/(.+)es$/', $word, $m)) {
        $stem = $m[1];
        if (preg_match('/(s|x|z|ch|sh)$/', $stem)) {
            return $stem;
        }
    }
    if (substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
        return substr($word, 0, -1);
    }

    return $word;
}

/**
 * Rewrite the class docblock for a factory:
 * - remove legacy @method getEntity()/getEntities()/persist() lines
 * - preserve all other docblock content
 * - insert the canonical @extends line before the docblock close
 *
 * Returns null if the class docblock cannot be located.
 */
function rewriteClassDocblock(string $src, string $extendsLine): ?string
{
    if (
        !preg_match(
            '/\/\*\*.*?\*\/\s*(?:abstract\h+|final\h+)?class\h+\w+Factory\b/s',
            $src,
            $match,
            PREG_OFFSET_CAPTURE,
        )
    ) {
        return null;
    }

    $matchedBlock = $match[0][0];
    $offset = $match[0][1];
    if (!preg_match('/^(\/\*\*.*?\*\/)(\s*(?:abstract\h+|final\h+)?class\h+\w+Factory\b.*)$/s', $matchedBlock, $parts)) {
        return null;
    }

    $docblock = $parts[1];
    $newline = str_contains($docblock, "\r\n") ? "\r\n" : "\n";
    $lines = preg_split('/\R/', $docblock);
    if ($lines === false || count($lines) < 2) {
        return null;
    }

    $filteredLines = [];
    foreach ($lines as $line) {
        if (isLegacyFactoryMethodAnnotation($line)) {
            continue;
        }
        $filteredLines[] = $line;
    }

    $closingLine = array_pop($filteredLines);
    if ($closingLine === null || trim($closingLine) !== '*/') {
        return null;
    }

    while ($filteredLines !== [] && isDocblockBlankLine((string)end($filteredLines))) {
        array_pop($filteredLines);
    }

    $filteredLines[] = $extendsLine;
    $filteredLines[] = $closingLine;
    $rewrittenDocblock = implode($newline, $filteredLines);

    return substr_replace($src, $rewrittenDocblock, $offset, strlen($docblock));
}

function isLegacyFactoryMethodAnnotation(string $line): bool
{
    return (bool)preg_match('/^\h*\*\h*@method\b.*\h+(getEntity|getEntities|persist)\(\)\h*$/', $line);
}

function isDocblockBlankLine(string $line): bool
{
    return (bool)preg_match('/^\h*\*\h*$/', $line);
}

function extendsBaseFactory(string $content): bool
{
    if (preg_match('/\bextends\h+\\\\?CakephpFixtureFactories\\\\Factory\\\\BaseFactory\b/', $content)) {
        return true;
    }
    if (
        !preg_match(
            '/\buse\h+\\\\?CakephpFixtureFactories\\\\Factory\\\\BaseFactory(?:\h+as\h+(\w+))?\h*;/',
            $content,
            $useMatch,
        )
    ) {
        return false;
    }
    $alias = $useMatch[1] ?? 'BaseFactory';

    return (bool)preg_match('/\bextends\h+' . preg_quote($alias, '/') . '\b/', $content);
}

function usage(): void
{
    fwrite(STDERR, <<<TXT
Usage: php migrate-factory-annotations.php [--dry-run] PATH [PATH ...]

  PATH        File or directory to scan (recursively for directories).
  --dry-run   Print what would change without modifying files.
  --help      Show this message.

TXT);
}
