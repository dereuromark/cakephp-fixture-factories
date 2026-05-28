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

/**
 * Returns a real but non-belongsTo alias ('Articles', a belongsToMany on
 * the Authors table) from requiredParentAssociations(), so the
 * type-check guard for withRequiredParents() can be regression-tested.
 *
 * @extends \CakephpFixtureFactories\Test\Factory\RequiredParentsAuthorFactory
 */
class NonBelongsToRequiredParentAliasAuthorFactory extends RequiredParentsAuthorFactory
{
    /**
     * @return array<int, string>
     */
    protected function requiredParentAssociations(): array
    {
        return ['Articles'];
    }
}
