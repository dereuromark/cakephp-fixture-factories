<?php
declare(strict_types=1);

namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Same as RequiredParentsAuthorFactory, but invokes withRequiredParents()
 * from configure() (the canonical "factory-encoded default" form used by
 * downstream consumers) rather than expecting callers to chain it on at
 * `::new(...)` sites.
 *
 * @extends BaseFactory<\TestApp\Model\Entity\Author>
 */
class RequiredParentsAuthorConfiguredFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Authors';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->name(),
        ];
    }

    protected function configure(): static
    {
        return $this->withRequiredParents();
    }
}
