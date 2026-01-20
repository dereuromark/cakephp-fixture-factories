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
 * Class BillFactory
 * @method \TestPlugin\Model\Entity\Bill getEntity()
 * @method \TestPlugin\Model\Entity\Bill[] getEntities()
 * @method \TestPlugin\Model\Entity\Bill|\TestPlugin\Model\Entity\Bill[] persist()
 * @method static \TestPlugin\Model\Entity\Bill get(mixed $primaryKey, array $options = [])
 */
class BillFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'TestPlugin.Bills';
    }

    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(function (GeneratorInterface $generator) {
            return [
                'amount' => $generator->numberBetween(0, 1000),
            ];
        })
        ->withArticle()
        ->withCustomer()
        ->listeningToModelEvents([
            'Model.beforeMarshal',
            'Model.afterSave',
        ]);
    }

    public function withArticle(mixed $parameter = null): self
    {
        if (is_numeric($parameter)) {
            $articleFactory = ArticleFactory::make()->times((int)$parameter);
        } elseif ($parameter instanceof EntityInterface) {
            $articleFactory = ArticleFactory::makeFrom($parameter);
        } elseif (is_callable($parameter)) {
            $articleFactory = ArticleFactory::makeWith($parameter);
        } else {
            $articleFactory = ArticleFactory::make($parameter);
        }

        return $this->with('Article', $articleFactory);
    }

    public function withCustomer(mixed $parameter = null): self
    {
        if (is_numeric($parameter)) {
            $customerFactory = CustomerFactory::make()->times((int)$parameter);
        } elseif ($parameter instanceof EntityInterface) {
            $customerFactory = CustomerFactory::makeFrom($parameter);
        } elseif (is_callable($parameter)) {
            $customerFactory = CustomerFactory::makeWith($parameter);
        } else {
            $customerFactory = CustomerFactory::make($parameter);
        }

        return $this->with('Customer', $customerFactory);
    }
}
