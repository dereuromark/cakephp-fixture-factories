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

        if ($this->isName($node->name, 'get')) {
            return new MethodCall(
                new StaticCall($node->class, new Identifier('table')),
                new Identifier('get'),
                $node->args,
            );
        }

        // Factory::find() with no args -> Factory::query(). CakePHP 5's
        // SelectQuery::find() requires a finder name, so chaining ->find() onto
        // Factory::query() (which already returns a default 'all' SelectQuery)
        // would error. Factory::find('all', ...) keeps the chained form.
        if ($this->isName($node->name, 'find') && $node->args === []) {
            return new StaticCall($node->class, new Identifier('query'));
        }

        foreach (['find', 'firstOrFail', 'count'] as $queryMethod) {
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
