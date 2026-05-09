<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryFindZeroArgsExample
{
    public function migrate(): void
    {
        $entry = ArticleFactory::find();
        $first = ArticleFactory::find()->firstOrFail();
        $byFinder = ArticleFactory::find('published')->firstOrFail();
    }
}
-----
<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryFindZeroArgsExample
{
    public function migrate(): void
    {
        $entry = ArticleFactory::query();
        $first = ArticleFactory::query()->firstOrFail();
        $byFinder = ArticleFactory::query()->find('published')->firstOrFail();
    }
}
