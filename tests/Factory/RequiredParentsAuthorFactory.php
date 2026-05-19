<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * A bare Authors factory with NO `configure()` defaults and NO `forFoo()`
 * helpers, so `withRequiredParents()` is exercised in isolation. The Authors
 * table has a NOT NULL `address_id` (Address belongsTo — required) and a
 * nullable `business_address_id` (BusinessAddress belongsTo — optional).
 *
 * @extends BaseFactory<\TestApp\Model\Entity\Author>
 */
class RequiredParentsAuthorFactory extends BaseFactory
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
}
