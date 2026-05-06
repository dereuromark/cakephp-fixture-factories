<?php

declare(strict_types=1);

namespace App\Service;

use CakephpFixtureFactories\Test\Factory\ArticleFactory;

final class FactoryLegacyMakeAmbiguousIntBail
{
    /**
     * @param int|array<string, mixed> $countOrData
     */
    public function migrate(int|array $countOrData): void
    {
        // Static type is int|array — rector cannot safely choose between
        // new($data) and new()->count($n), so it should bail.
        $factory = ArticleFactory::make($countOrData);
    }
}
