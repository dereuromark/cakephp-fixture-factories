<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryLegacyMakeMixedArgRenames
{
    /**
     * Untyped parameter — phpstan resolves it as mixed. Historically
     * `make($single_arg)` meant data, not count, so the rule should
     * fall through to the new() rename rather than bailing.
     */
    public function migrate($parameter): void
    {
        $factory = ArticleFactory::make($parameter);
    }

    /**
     * Explicit mixed type behaves the same way.
     */
    public function migrateMixed(mixed $parameter): void
    {
        $factory = ArticleFactory::make($parameter);
    }
}
-----
<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryLegacyMakeMixedArgRenames
{
    /**
     * Untyped parameter — phpstan resolves it as mixed. Historically
     * `make($single_arg)` meant data, not count, so the rule should
     * fall through to the new() rename rather than bailing.
     */
    public function migrate($parameter): void
    {
        $factory = ArticleFactory::new($parameter);
    }

    /**
     * Explicit mixed type behaves the same way.
     */
    public function migrateMixed(mixed $parameter): void
    {
        $factory = ArticleFactory::new($parameter);
    }
}
