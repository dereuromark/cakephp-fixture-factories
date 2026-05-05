<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Rector\StaticCall;

use CakephpFixtureFactories\Factory\BaseFactory;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;

final class FactoryStaticQueryRector extends AbstractRector
{
    /**
     * @return array<class-string<\PhpParser\Node>>
     */
    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    /**
     * @param \PhpParser\Node\Expr\StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$node->class instanceof Name) {
            return null;
        }

        if (!$this->isObjectType($node->class, new ObjectType(BaseFactory::class))) {
            return null;
        }

        foreach (['find', 'get', 'firstOrFail', 'count'] as $queryMethod) {
            if ($this->isName($node->name, $queryMethod)) {
                return new MethodCall(
                    new StaticCall($node->class, new Identifier('query')),
                    new Identifier($queryMethod),
                    $node->args,
                );
            }
        }

        return null;
    }
}
