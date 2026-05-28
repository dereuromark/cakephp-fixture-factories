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
 * Class BillFactory
 *
 * @extends \CakephpFixtureFactories\Factory\BaseFactory<\TestPlugin\Model\Entity\Bill>
 */
class BillFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'TestPlugin.Bills';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'amount' => $generator->numberBetween(0, 1000),
        ];
    }

    protected function configure(): static
    {
        return $this
            ->forArticle()
            ->forCustomer()
            ->listeningToModelEvents([
                'Model.beforeMarshal',
                'Model.afterSave',
            ]);
    }

    public function forArticle(mixed $parameter = null): self
    {
        return $this->for(ArticleFactory::new($parameter));
    }

    public function forCustomer(mixed $parameter = null): self
    {
        return $this->for(CustomerFactory::new($parameter));
    }
}
