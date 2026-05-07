<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

class TaggedFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Tags.Tagged';
    }

    /**
     * @return void
     */
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(fn (): array => [
            'tag_id' => 1,
            'fk_id' => 1,
            'fk_table' => 'sandbox_posts',
        ]);
    }
}
-----
<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;

class TaggedFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Tags.Tagged';
    }

    public function definition(\CakephpFixtureFactories\Generator\GeneratorInterface $generator): array
    {
        return [
            'tag_id' => 1,
            'fk_id' => 1,
            'fk_table' => 'sandbox_posts',
        ];
    }
}
