<?php

declare(strict_types=1);

namespace App\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

final class UserFactory extends BaseFactory
{
    public const int ROLE_ADMIN = 2;

    protected function getRootTableRegistryName(): string
    {
        return 'Users';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'username' => $generator->userName(),
            'role_id' => self::ROLE_ADMIN,
        ];
    }

    public function asAdmin(): static
    {
        return $this->patchData(['role_id' => self::ROLE_ADMIN]);
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
    public const int ROLE_ADMIN = 2;

    protected function getRootTableRegistryName(): string
    {
        return 'Users';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'username' => $generator->userName(),
            'role_id' => self::ROLE_ADMIN,
        ];
    }

    public function asAdmin(): static
    {
        return $this->state(['role_id' => self::ROLE_ADMIN]);
    }
}
