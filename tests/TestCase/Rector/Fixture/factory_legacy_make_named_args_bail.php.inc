<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryLegacyMakeNamedArgsBail
{
    public function migrate(): void
    {
        // Named args make the rector unable to safely rewrite, so it should bail
        // rather than emit invalid code like new(times: 3)->count(...).
        $batch = ArticleFactory::make(times: 3);
        $batchWithData = ArticleFactory::make(data: ['title' => 'X'], times: 3);
        $many = ArticleFactory::makeMany(times: 4);
    }
}
