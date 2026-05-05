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

$pattern = '/^\h*\*\h*@method\h+\\\\?[\w\\\\]+\h+getEntity\(\)\R'
    . '\h*\*\h*@method\h+array<\\\\?[\w\\\\]+>\h+getEntities\(\)\R'
    . '\h*\*\h*@method\h+\\\\?[\w\\\\]+\|array<\\\\?[\w\\\\]+>\h+persist\(\)\R/m';

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
        $replacementLine = sprintf(" * @extends \\CakephpFixtureFactories\\Factory\\BaseFactory<%s>\n", $entity);
        $new = preg_replace($pattern, $replacementLine, $src, 1, $count);
        if ($count === 0 || $new === null) {
            // Legacy block not found, but extends BaseFactory: try inserting @extends before the closing */ of the class docblock.
            $new = insertExtendsLine($src, $replacementLine);
            if ($new === null) {
                $skippedNoMatch++;

                continue;
            }
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
 * Derive the entity FQN from the file's namespace + class name.
 *
 * App\Test\Factory\InvoiceFactory -> \App\Model\Entity\Invoice
 * MyPlugin\Test\Factory\ThingFactory -> \MyPlugin\Model\Entity\Thing
 *
 * Returns null if the file does not contain a namespace + class declaration
 * matching the convention.
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
    $entityNs = preg_replace('/\\\\Test\\\\Factory$/', '\\Model\\Entity', $namespace);
    if ($entityNs === $namespace) {
        // Namespace did not end with \Test\Factory — fall back to a best-effort guess.
        $entityNs = $namespace . '\\Model\\Entity';
    }

    return '\\' . $entityNs . '\\' . $entityName;
}

/**
 * Insert an @extends line into the class docblock when no legacy block is present.
 *
 * Returns null if the docblock cannot be located.
 */
function insertExtendsLine(string $src, string $extendsLine): ?string
{
    if (!preg_match('/(\/\*\*[^*]*(?:\*(?!\/)[^*]*)*)\*\/\s*(?:abstract\h+|final\h+)?class\h+\w+Factory\b/m', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $docblock = $m[1][0];
    $offset = $m[1][1];
    $newDocblock = rtrim($docblock) . "\n" . $extendsLine . "\n ";

    return substr_replace($src, $newDocblock, $offset, strlen($docblock));
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
