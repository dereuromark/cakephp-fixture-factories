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
 * Class CustomerFactory
 *
 * @extends BaseFactory<\TestPlugin\Model\Entity\Customer>
 *
 * @method static \TestPlugin\Model\Entity\Customer get(mixed $primaryKey, array $options = [])
 */
class CustomerFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'TestPlugin.Customers';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->lastName(),
        ];
    }

    /**
     * @param array|callable|null|int|\Cake\Datasource\EntityInterface $parameter Injected data
     * @param int $n
     * @return CustomerFactory
     */
    public function hasBills($n = 1, $parameter = null): self
    {
        if (!is_int($n)) {
            $originalParameter = $n;
            $n = is_int($parameter) ? $parameter : 1;
            $parameter = $originalParameter;
        }

        return $this->has(BillFactory::new($parameter)->count($n)->without('Customer'));
    }

    public function withBills($parameter = null, int $n = 1): self
    {
        if (is_int($parameter) && $n === 1) {
            return $this->hasBills($parameter);
        }

        return $this->hasBills($n, $parameter);
    }

    /**
     * @param array|callable|null|int|\Cake\Datasource\EntityInterface $parameter Injected data
     * @return CustomerFactory
     */
    public function forAddress($parameter = null): self
    {
        return $this->for(AddressFactory::new($parameter));
    }

    public function withAddress($parameter = null): self
    {
        return $this->forAddress($parameter);
    }
}
