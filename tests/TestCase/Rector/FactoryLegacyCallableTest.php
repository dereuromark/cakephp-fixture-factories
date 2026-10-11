<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Rector;

use CakephpFixtureFactories\Rector\StaticCall\FactoryLegacyMakeToNewRector;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\VariadicPlaceholder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class FactoryLegacyCallableTest extends TestCase
{
    public function testFirstClassCallablesAreNotRewritten(): void
    {
        $reflection = new ReflectionClass(FactoryLegacyMakeToNewRector::class);
        $rector = $reflection->newInstanceWithoutConstructor();

        foreach (['make', 'makeMany', 'makeWith'] as $method) {
            $call = new StaticCall(new Name('ArticleFactory'), $method, [new VariadicPlaceholder()]);

            $this->assertNull($rector->refactor($call));
            $this->assertInstanceOf(VariadicPlaceholder::class, $call->args[0]);
            $this->assertSame($method, $call->name->toString());
        }
    }
}
