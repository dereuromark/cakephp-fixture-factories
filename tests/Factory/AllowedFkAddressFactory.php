<?php
declare(strict_types=1);

namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Returns a non-managed custom join column from `definition()` and explicitly
 * declares it intentional via
 * {@see BaseFactory::allowedForeignKeysInDefinition()}. Models the supported
 * exception for `foreignKey => false` condition-join columns.
 *
 * @extends BaseFactory<\TestApp\Model\Entity\Address>
 */
class AllowedFkAddressFactory extends BaseFactory
{
    protected function initialize(): void
    {
        if (!$this->getTable()->hasAssociation('GhostCity')) {
            $this->getTable()->belongsTo('GhostCity', [
                'className' => 'Cities',
                'foreignKey' => false,
                'conditions' => ['Addresses.city_uuid = GhostCity.uuid'],
            ]);
        }
    }

    protected function getRootTableRegistryName(): string
    {
        return 'Addresses';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'street' => $generator->streetAddress(),
            'city_uuid' => $generator->uuid(),
        ];
    }

    protected function allowedForeignKeysInDefinition(): array
    {
        return ['city_uuid'];
    }
}
