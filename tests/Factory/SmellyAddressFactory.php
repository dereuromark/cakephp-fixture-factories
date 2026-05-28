<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * A factory that deliberately returns the FK column `city_id` from
 * `definition()` so the FK-in-definition detector test suite has an offender
 * to assert against. Production factories should never be shaped this way.
 *
 * @extends \CakephpFixtureFactories\Factory\BaseFactory<\TestApp\Model\Entity\Address>
 */
class SmellyAddressFactory extends BaseFactory
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
}
