<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

final class UserFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Users';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'username' => $generator->userName,
            'email' => $generator->email,
            'biography' => $generator->optional()->realText,
            'token' => $generator->unique()->uuid,
        ];
    }

    public function withName(): static
    {
        return $this->setField('name', $this->getGenerator()->name);
    }
}
-----
<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

final class UserFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Users';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'username' => $generator->userName(),
            'email' => $generator->email(),
            'biography' => $generator->optional()->realText(),
            'token' => $generator->unique()->uuid(),
        ];
    }

    public function withName(): static
    {
        return $this->setField('name', $this->getGenerator()->name());
    }
}
