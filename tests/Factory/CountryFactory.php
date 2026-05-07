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
 * Class CountryFactory
 *
 * @extends BaseFactory<\TestApp\Model\Entity\Country>
 */
class CountryFactory extends BaseFactory
{
    protected array $uniqueProperties = [
        'unique_stamp',
    ];

    protected function getRootTableRegistryName(): string
    {
        return 'Countries';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->country(),
            'unique_stamp' => $generator->uuid(),
        ];
    }
}
