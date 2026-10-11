<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\ORM;

use Cake\ORM\Association;
use Cake\ORM\AssociationCollection;
use Cake\ORM\Table;

/**
 * Keeps association targets in the fixture factory locator.
 *
 * @internal
 */
class FactoryAssociationCollection extends AssociationCollection
{
    /**
     * @inheritDoc
     */
    public function load(string $className, string $associated, Table $sourceTable, array $options = []): Association
    {
        $options += ['tableLocator' => $this->getTableLocator()];

        return parent::load($className, $associated, $sourceTable, $options);
    }
}
