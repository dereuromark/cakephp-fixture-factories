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

use Cake\Datasource\EntityInterface;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Class CustomerFactory
 * @method \TestPlugin\Model\Entity\Customer getEntity()
 * @method \TestPlugin\Model\Entity\Customer[] getEntities()
 * @method \TestPlugin\Model\Entity\Customer|\TestPlugin\Model\Entity\Customer[] persist()
 * @method static \TestPlugin\Model\Entity\Customer get(mixed $primaryKey, array $options = [])
 */
class CustomerFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'TestPlugin.Customers';
    }

    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(function (GeneratorInterface $generator) {
            return [
                'name' => $generator->lastName(),
            ];
        });
    }

    /**
     * @param array|callable|null|int|\Cake\Datasource\EntityInterface $parameter Injected data
     * @param int $n
     * @return CustomerFactory
     */
    public function withBills($parameter = null, int $n = 1): self
    {
        if (is_numeric($parameter)) {
            $billsFactory = BillFactory::make()->setTimes((int)$parameter)->without('Customer');
        } elseif ($parameter instanceof EntityInterface) {
            $billsFactory = BillFactory::makeFrom($parameter)->setTimes($n)->without('Customer');
        } elseif (is_callable($parameter)) {
            $billsFactory = BillFactory::makeWith($parameter)->setTimes($n)->without('Customer');
        } else {
            $billsFactory = BillFactory::make($parameter)->setTimes($n)->without('Customer');
        }

        return $this->with('Bills', $billsFactory);
    }

    /**
     * @param array|callable|null|int|\Cake\Datasource\EntityInterface $parameter Injected data
     * @return CustomerFactory
     */
    public function withAddress($parameter = null): self
    {
        if (is_numeric($parameter)) {
            $addressFactory = AddressFactory::make()->setTimes((int)$parameter);
        } elseif ($parameter instanceof EntityInterface) {
            $addressFactory = AddressFactory::makeFrom($parameter);
        } elseif (is_callable($parameter)) {
            $addressFactory = AddressFactory::makeWith($parameter);
        } else {
            $addressFactory = AddressFactory::make($parameter);
        }

        return $this->with('Address', $addressFactory);
    }
}
