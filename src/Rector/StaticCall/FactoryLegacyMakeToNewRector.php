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
use PHPStan\Type\UnionType;
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
            $countArg = $this->resolveArg($node->args[0] ?? null);
            if ($countArg === null || $this->hasNamedArgs([$countArg])) {
                return null;
            }

            return $this->createCountCall($this->createNewCall($node->class), $countArg);
        }

        if ($this->isName($node->name, 'makeWith')) {
            $args = $this->normalizeArgs($node->args);
            if ($this->hasNamedArgs($args)) {
                return null;
            }

            return $this->createNewCall($node->class, $args);
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
            if (!$firstArg instanceof Arg || $this->hasNamedArgs([$firstArg])) {
                return null;
            }

            $type = $this->getType($firstArg->value);
            if ($type->isInteger()->yes()) {
                return $this->createCountCall($this->createNewCall($node->class), $firstArg);
            }

            // Bail only on concretely ambiguous int|array unions where rector
            // genuinely cannot choose between new($data) and new()->count($n).
            // For mixed/unknown/untyped variables, fall through to the new()
            // rename — historically `make($single_arg)` meant data, not count.
            if (
                $type instanceof UnionType
                && $type->isInteger()->maybe()
                && $type->isArray()->maybe()
            ) {
                return null;
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

            if ($this->hasNamedArgs([$firstArg, $secondArg])) {
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
     * @param array<\PhpParser\Node\Arg> $args
     */
    private function hasNamedArgs(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg->name !== null) {
                return true;
            }
        }

        return false;
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
