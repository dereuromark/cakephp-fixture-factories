<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Rector\PropertyFetch;

use CakephpFixtureFactories\Generator\GeneratorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;

/**
 * Converts Faker-style property access on a GeneratorInterface into a method call:
 * $generator->name -> $generator->name().
 *
 * Covers the OptionalGeneratorInterface and UniqueGeneratorInterface sub-interfaces
 * as well, since both extend GeneratorInterface.
 */
final class GeneratorPropertyToMethodCallRector extends AbstractRector
{
    /**
     * @return array<class-string<\PhpParser\Node>>
     */
    public function getNodeTypes(): array
    {
        return [PropertyFetch::class];
    }

    /**
     * @param \PhpParser\Node\Expr\PropertyFetch $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$node->name instanceof Identifier) {
            return null;
        }

        if (!$this->isObjectType($node->var, new ObjectType(GeneratorInterface::class))) {
            return null;
        }

        return new MethodCall($node->var, new Identifier($node->name->toString()));
    }
}
