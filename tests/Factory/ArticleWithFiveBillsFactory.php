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

class ArticleWithFiveBillsFactory extends ArticleFactory
{
    public function definition(\CakephpFixtureFactories\Generator\GeneratorInterface $generator): array
    {
        return [
            ...parent::definition($generator),
            'title' => 'Article with 5 bills',
        ];
    }

    protected function configure(): static
    {
        return parent::configure()->hasBills(null, 5);
    }
}
