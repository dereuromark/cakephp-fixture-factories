<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Reproduces the shared-primary-key 1:1 case for the FK-in-definition
 * detector test suite: an `id` column that is both the entity's own PK
 * and a belongsTo foreign key. The matching belongsTo association is
 * registered at runtime inside the test (the test app's Addresses table
 * does not declare it by default).
 *
 * Production factories should never pin `id` like this when `id` is also
 * a belongsTo FK — see SmellyAddressFactory for the regular-FK variant.
 *
 * @extends \CakephpFixtureFactories\Factory\BaseFactory<\TestApp\Model\Entity\Address>
 */
class SharedPkSmellyAddressFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'Addresses';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'id' => 999_999,
            'street' => $generator->streetAddress(),
        ];
    }
}
