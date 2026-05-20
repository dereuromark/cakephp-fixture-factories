<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.5
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Opts a composite-key belongsTo (`CompositeCity`, registered at runtime)
 * into `withRequiredParents()` via the additive hook, exercising the
 * documented composite-key opt-in path end to end.
 *
 * @extends \CakephpFixtureFactories\Test\Factory\TableWithoutModelFactory
 */
class RequiredParentsCompositeOptInTableWithoutModelFactory extends TableWithoutModelFactory
{
    protected function initialize(): void
    {
        if (!$this->getTable()->hasAssociation('CompositeCity')) {
            $this->getTable()->belongsTo('CompositeCity', [
                'className' => 'Cities',
                'foreignKey' => ['foreign_key', 'country_id'],
                'bindingKey' => ['id', 'country_id'],
            ]);
        }
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->text(120),
            'binding_key' => $generator->randomNumber(),
            'created' => $generator->dateTime(),
            'modified' => $generator->dateTime(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function requiredParentAssociations(): array
    {
        return ['CompositeCity'];
    }
}
