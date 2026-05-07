<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryMigrationExample
{
    public function migrate(): void
    {
        $draft = ArticleFactory::make()->getEntity();
        $batch = ArticleFactory::make(['title' => 'Published'], 3)->persistEntities();
        $article = ArticleFactory::get(5, ['contain' => ['Authors']]);
        $total = ArticleFactory::count();
        $published = ArticleFactory::find('published')->firstOrFail();
    }
}
-----
<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryMigrationExample
{
    public function migrate(): void
    {
        $draft = ArticleFactory::new()->build();
        $batch = ArticleFactory::new(['title' => 'Published'])->count(3)->saveMany();
        $article = ArticleFactory::table()->get(5, ['contain' => ['Authors']]);
        $total = ArticleFactory::query()->count();
        $published = ArticleFactory::query()->find('published')->firstOrFail();
    }
}
