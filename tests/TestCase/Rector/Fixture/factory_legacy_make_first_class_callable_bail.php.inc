<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryLegacyFirstClassCallable
{
    public function callbacks(): array
    {
        return [
            ArticleFactory::make(...),
            ArticleFactory::makeMany(...),
            ArticleFactory::makeWith(...),
        ];
    }
}
