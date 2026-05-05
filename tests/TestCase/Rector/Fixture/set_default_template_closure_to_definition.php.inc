<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class TagFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Tags.Tags';
    }

    /**
     * @return void
     */
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(function (GeneratorInterface $generator): array {
            $label = $generator->unique()->word();

            return [
                'slug' => strtolower($label),
                'label' => $label,
            ];
        });
    }
}
-----
<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class TagFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Tags.Tags';
    }

    public function definition(\CakephpFixtureFactories\Generator\GeneratorInterface $generator): array
    {
        $label = $generator->unique()->word();
        return [
            'slug' => strtolower($label),
            'label' => $label,
        ];
    }
}
