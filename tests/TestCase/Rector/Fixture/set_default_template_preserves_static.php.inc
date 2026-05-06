<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

abstract class AbstractStaticFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Tags.Tags';
    }

    final protected static function setDefaultTemplate(): void
    {
        $this->setDefaultData(fn (): array => [
            'slug' => 'static-slug',
        ]);
    }
}
-----
<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

abstract class AbstractStaticFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Tags.Tags';
    }

    final public static function definition(\CakephpFixtureFactories\Generator\GeneratorInterface $generator): array
    {
        return [
            'slug' => 'static-slug',
        ];
    }
}
