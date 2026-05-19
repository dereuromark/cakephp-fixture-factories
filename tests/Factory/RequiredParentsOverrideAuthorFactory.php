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
 * Opts the (otherwise nullable, thus not auto-resolved) `BusinessAddress`
 * belongsTo into `withRequiredParents()` via the override hook, demonstrating
 * the supported, non-guessing escape hatch for associations automatic
 * detection deliberately refuses (composite / custom-join / nullable).
 *
 * @extends \CakephpFixtureFactories\Test\Factory\RequiredParentsAuthorFactory
 */
class RequiredParentsOverrideAuthorFactory extends RequiredParentsAuthorFactory
{
    /**
     * @return array<int, string>|null
     */
    protected function requiredParentAssociations(): ?array
    {
        return ['Address', 'BusinessAddress'];
    }
}
