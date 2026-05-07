<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Rector\ClassMethod;

use CakephpFixtureFactories\Generator\GeneratorInterface;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use Rector\Rector\AbstractRector;

final class FactorySetDefaultTemplateToDefinitionRector extends AbstractRector
{
    /**
     * @return array<class-string<\PhpParser\Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    /**
     * @param \PhpParser\Node\Stmt\ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$this->isName($node->name, 'setDefaultTemplate')) {
            return null;
        }

        if (count((array)$node->stmts) !== 1) {
            return null;
        }

        $statement = $node->stmts[0] ?? null;
        if (!$statement instanceof Expression) {
            return null;
        }

        $methodCall = $statement->expr;
        if (!$methodCall instanceof MethodCall) {
            return null;
        }

        if (!$methodCall->var instanceof Variable || !$this->isName($methodCall->var, 'this')) {
            return null;
        }

        if (!$this->isName($methodCall->name, 'setDefaultData')) {
            return null;
        }

        $callbackArg = $methodCall->args[0] ?? null;
        if (!$callbackArg instanceof Arg) {
            return null;
        }

        $callback = $callbackArg->value;
        if (!$callback instanceof Closure && !$callback instanceof ArrowFunction) {
            return null;
        }

        if (count($callback->params) > 1) {
            return null;
        }

        $node->flags = ($node->flags & ~Class_::VISIBILITY_MODIFIER_MASK) | Class_::MODIFIER_PUBLIC;
        $node->name = new Identifier('definition');
        $node->params = [$this->createDefinitionParam($callback->params[0] ?? null)];
        $node->returnType = new Identifier('array');
        $node->stmts = $callback instanceof Closure ? $callback->stmts : [new Return_($callback->expr)];
        // Strip the original docblock since @return void / @param Faker are no longer accurate.
        $node->setAttribute('comments', []);

        return $node;
    }

    private function createDefinitionParam(?Param $param): Param
    {
        if ($param === null) {
            return new Param(new Variable('generator'), null, new FullyQualified(GeneratorInterface::class));
        }

        $param->type = $this->normalizeGeneratorType($param->type);

        return $param;
    }

    private function normalizeGeneratorType(ComplexType|Name|Identifier|FullyQualified|null $type): Name|FullyQualified
    {
        if ($type instanceof Name && $type->toString() === 'GeneratorInterface') {
            return $type;
        }

        return new FullyQualified(GeneratorInterface::class);
    }
}
