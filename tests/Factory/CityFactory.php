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
 * Class CityFactory
 *
 * @extends BaseFactory<\TestApp\Model\Entity\City>
 *
 * @method static \TestApp\Model\Entity\City get(mixed $primaryKey, array $options = [])
 */
class CityFactory extends BaseFactory
{
    protected array $uniqueProperties = [
        'virtual_unique_stamp',
    ];

    protected function initialize(): void
    {
        if (!$this->getTable()->hasAssociation('TableWithoutModel')){
            $this->getTable()->hasMany('TableWithoutModel', [
                'foreignKey' => 'foreign_key',
            ]);
        }
    }

    protected function getRootTableRegistryName(): string
    {
        return 'Cities';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->city(),
        ];
    }

    /**
     * @param array|callable|null|int $parameter
     * @return $this
     */
    protected function configure(): static
    {
        return $this->forCountries();
    }

    public function forCountries(mixed $parameter = null): self
    {
        return $this->for(CountryFactory::new($parameter));
    }

    public function withCountries(mixed $parameter = null): self
    {
        return $this->forCountries($parameter);
    }
}
