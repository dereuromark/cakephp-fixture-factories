<?php

declare(strict_types=1);

namespace TestPlugin\Model\Table;

use Cake\ORM\Table;

/**
 * Shares its bare alias with the app's CountriesTable on purpose.
 */
class CountriesTable extends Table
{
    public function initialize(array $config): void
    {
        $this->setTable('countries');

        parent::initialize($config);
    }
}
