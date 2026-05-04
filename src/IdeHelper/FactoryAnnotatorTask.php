<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\IdeHelper;

use IdeHelper\Annotation\AnnotationFactory;
use IdeHelper\Annotation\ExtendsAnnotation;
use IdeHelper\Annotator\ClassAnnotatorTask\AbstractClassAnnotatorTask;
use IdeHelper\Annotator\ClassAnnotatorTask\PathAwareClassAnnotatorTaskInterface;

/**
 * Class annotator task that ensures every Factory subclass declares the
 * generic-extends docblock contract:
 *
 *     extends \CakephpFixtureFactories\Factory\BaseFactory<\App\Model\Entity\X>
 *
 * Without this annotation, IDEs and PHPStan/Psalm cannot resolve the
 * TEntity template on BaseFactory, so getEntity(), getEntities(),
 * persistEntity(), persistEntities(), getResultSet() and
 * getPersistedResultSet() all fall back to EntityInterface.
 *
 * Implements `PathAwareClassAnnotatorTaskInterface` so that the standard
 * `bin/cake annotate classes` command (and `annotate all`) reach
 * `tests/Factory/` automatically once this plugin's bootstrap registers
 * the task. No dedicated subcommand is required.
 *
 * Also strips the legacy three-line method block (getEntity / getEntities
 * / persist) emitted by pre-1.4 bake output, so first runs over an
 * existing codebase migrate the docblock in one pass; subsequent runs
 * are no-ops.
 */
class FactoryAnnotatorTask extends AbstractClassAnnotatorTask implements PathAwareClassAnnotatorTaskInterface
{
    /**
     * @var string
     */
    public const BASE_FACTORY_FQN = '\\CakephpFixtureFactories\\Factory\\BaseFactory';

    /**
     * @return array<string>
     */
    public static function scanPaths(): array
    {
        return ['tests/Factory/'];
    }

    /**
     * @param string $path
     * @param string $content
     *
     * @return bool
     */
    public function shouldRun(string $path, string $content): bool
    {
        if (!str_contains($path, DIRECTORY_SEPARATOR . 'Factory' . DIRECTORY_SEPARATOR)) {
            return false;
        }
        if (!preg_match('/^class\s+\w+Factory\b/m', $content)) {
            return false;
        }

        return $this->extendsBaseFactory($content);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function annotate(string $path): bool
    {
        $entityFqn = $this->deriveEntityFqn($this->content);
        if ($entityFqn === null) {
            return false;
        }

        $type = self::BASE_FACTORY_FQN . '<' . $entityFqn . '>';
        $annotations = [
            AnnotationFactory::createOrFail(ExtendsAnnotation::TAG, $type),
        ];

        $cleaned = $this->stripLegacyMethodBlock($this->content);
        if ($cleaned !== $this->content) {
            $this->content = $cleaned;
        }

        return $this->annotateContent($path, $this->content, $annotations);
    }

    /**
     * Detect whether the class extends BaseFactory, allowing for both
     * fully-qualified `extends \CakephpFixtureFactories\Factory\BaseFactory`
     * and aliased `use ... as Foo; class X extends Foo`.
     *
     * @param string $content
     *
     * @return bool
     */
    protected function extendsBaseFactory(string $content): bool
    {
        if (preg_match('/\bextends\s+\\\\?CakephpFixtureFactories\\\\Factory\\\\BaseFactory\b/', $content)) {
            return true;
        }
        if (
            !preg_match(
                '/\buse\s+\\\\?CakephpFixtureFactories\\\\Factory\\\\BaseFactory(?:\s+as\s+(\w+))?\s*;/',
                $content,
                $useMatch,
            )
        ) {
            return false;
        }
        $alias = $useMatch[1] ?? 'BaseFactory';

        return (bool)preg_match('/\bextends\s+' . preg_quote($alias, '/') . '\b/', $content);
    }

    /**
     * Derive the FQN of the entity this factory produces from the file's
     * namespace + class name.
     *
     * Convention:
     *   App\Test\Factory\InvoiceFactory -> \App\Model\Entity\Invoice
     *   MyPlugin\Test\Factory\ThingFactory -> \MyPlugin\Model\Entity\Thing
     *
     * Returns null if the file does not contain a recognizable namespace
     * + `<Name>Factory` class declaration.
     *
     * @param string $content
     *
     * @return string|null
     */
    protected function deriveEntityFqn(string $content): ?string
    {
        if (!preg_match('/^namespace\s+([\w\\\\]+);/m', $content, $nsMatch)) {
            return null;
        }
        if (!preg_match('/^class\s+(\w+)Factory\b/m', $content, $classMatch)) {
            return null;
        }
        $namespace = $nsMatch[1];
        $entityName = $classMatch[1];
        $entityNs = preg_replace('/\\\\Test\\\\Factory$/', '\\Model\\Entity', $namespace);
        if ($entityNs === $namespace) {
            $entityNs = $namespace . '\\Model\\Entity';
        }

        return '\\' . $entityNs . '\\' . $entityName;
    }

    /**
     * Strip the legacy three-line "method getEntity / getEntities / persist"
     * block emitted by pre-1.4 bake output. Any other docblock tags
     * (method, property, see, etc.) are preserved.
     *
     * @param string $content
     *
     * @return string
     */
    protected function stripLegacyMethodBlock(string $content): string
    {
        $pattern = '/^\h*\*\h*@method\h+\\\\?[\w\\\\]+\h+getEntity\(\)\R'
            . '\h*\*\h*@method\h+array<\\\\?[\w\\\\]+>\h+getEntities\(\)\R'
            . '\h*\*\h*@method\h+\\\\?[\w\\\\]+\|array<\\\\?[\w\\\\]+>\h+persist\(\)\R/m';

        return (string)preg_replace($pattern, '', $content);
    }
}
