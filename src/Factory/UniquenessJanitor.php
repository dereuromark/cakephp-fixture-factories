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
namespace CakephpFixtureFactories\Factory;

/**
 * Class UniquenessJanitor
 *
 * Minimal stub for backward compatibility
 */
class UniquenessJanitor
{
    /**
     * Sanitize entity array - stub for backward compatibility
     *
     * @param array<\Cake\Datasource\EntityInterface> $entities
     * @param array<string> $uniqueFields
     * @return array<\Cake\Datasource\EntityInterface>
     */
    public static function sanitizeEntityArray(array $entities, array $uniqueFields = []): array
    {
        // For v4, just return the entities as-is
        // Uniqueness is handled differently now
        return $entities;
    }
}
