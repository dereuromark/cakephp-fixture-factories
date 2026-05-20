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

/**
 * Drops the (otherwise auto-detected NOT NULL) `Address` belongsTo from
 * `withRequiredParents()` via the symmetric exclude hook — the
 * factory-class counterpart to the per-call `$except` argument, for when
 * the FK is legitimately satisfied another way (DB default, trigger, a
 * custom join the caller always supplies).
 *
 * @extends \CakephpFixtureFactories\Test\Factory\RequiredParentsAuthorFactory
 */
class RequiredParentsExcludeAuthorFactory extends RequiredParentsAuthorFactory
{
    /**
     * @return array<int, string>
     */
    protected function excludedRequiredParentAssociations(): array
    {
        return ['Address'];
    }
}
