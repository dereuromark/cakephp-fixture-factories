<?php
declare(strict_types=1);

namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Like {@see SmellyAddressFactory} (returns the `city_id` FK from
 * `definition()`), but explicitly declares that column intentional via
 * {@see BaseFactory::allowedForeignKeysInDefinition()}. Models the supported
 * exception for non-managed condition-join columns.
 *
 * @extends BaseFactory<\TestApp\Model\Entity\Address>
 */
class AllowedFkAddressFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Addresses';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'street' => $generator->streetAddress(),
            'city_id' => $generator->numberBetween(1, 100),
        ];
    }

    protected function allowedForeignKeysInDefinition(): array
    {
        return ['city_id'];
    }
}
