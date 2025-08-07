<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         3.1.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpFixtureFactories\Generator;

/**
 * Interface for unique value generators
 *
 * All generated values are guaranteed to be unique within the generator's lifetime.
 */
interface UniqueGeneratorInterface extends GeneratorInterface
{
    /**
     * Reset the unique values
     *
     * @return void
     */
    public function reset(): void;
}
