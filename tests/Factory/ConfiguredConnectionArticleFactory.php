<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\Factory;

class ConfiguredConnectionArticleFactory extends ArticleFactory
{
    protected function configure(): static
    {
        return $this->setConnection('dummy');
    }
}
