<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Rector\StaticCall;

use CakephpFixtureFactories\Factory\BaseFactory;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\VariadicPlaceholder;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;

final class FactoryLegacyMakeToNewRector extends AbstractRector
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

        if ($this->isName($node->name, 'makeMany')) {
            return $this->createCountCall($this->createNewCall($node->class), $this->resolveArg($node->args[0] ?? null));
        }

        if ($this->isName($node->name, 'makeWith')) {
            return $this->createNewCall($node->class, $this->normalizeArgs($node->args));
        }

        if (!$this->isName($node->name, 'make')) {
            return null;
        }

        $argsCount = count($node->args);
        if ($argsCount === 0) {
            return $this->createNewCall($node->class);
        }

        if ($argsCount === 1) {
            $firstArg = $this->resolveArg($node->args[0] ?? null);
            if (!$firstArg instanceof Arg) {
                return null;
            }

            if ($this->getType($firstArg->value)->isInteger()->yes()) {
                return $this->createCountCall($this->createNewCall($node->class), $firstArg);
            }

            $node->name = new Identifier('new');

            return $node;
        }

        if ($argsCount === 2) {
            $firstArg = $this->resolveArg($node->args[0] ?? null);
            $secondArg = $this->resolveArg($node->args[1] ?? null);
            if (!$firstArg instanceof Arg || !$secondArg instanceof Arg) {
                return null;
            }

            return $this->createCountCall(
                $this->createNewCall($node->class, [$firstArg]),
                $secondArg,
            );
        }

        return null;
    }

    /**
     * @param \PhpParser\Node\Name $class
     * @param array<\PhpParser\Node\Arg> $args
     */
    private function createNewCall(Name $class, array $args = []): StaticCall
    {
        return new StaticCall($class, new Identifier('new'), $args);
    }

    private function createCountCall(StaticCall $newCall, ?Arg $countArg): MethodCall
    {
        return new MethodCall(
            $newCall,
            new Identifier('count'),
            $countArg instanceof Arg ? [$countArg] : [],
        );
    }

    /**
     * @param array<\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder> $args
     *
     * @return array<\PhpParser\Node\Arg>
     */
    private function normalizeArgs(array $args): array
    {
        return array_values(array_filter($args, static fn (Arg|VariadicPlaceholder $arg): bool => $arg instanceof Arg));
    }

    private function resolveArg(Arg|VariadicPlaceholder|null $arg): ?Arg
    {
        return $arg instanceof Arg ? $arg : null;
    }
}
