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

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\ORM\Association\BelongsTo;

/**
 * Test double for CakePHP 5.4+'s join-key introspection API.
 *
 * @extends \Cake\ORM\Association\BelongsTo<\Cake\ORM\Table>
 */
class IntrospectableBelongsTo extends BelongsTo
{
    /**
     * @var array<int, string>
     */
    protected array $sourceJoinKey = [];

    /**
     * @var array<int, string>
     */
    protected array $targetJoinKey = [];

    /**
     * @return array<int, string>
     */
    public function getSourceJoinKey(): array
    {
        return $this->sourceJoinKey;
    }

    /**
     * @return array<int, string>
     */
    public function getTargetJoinKey(): array
    {
        return $this->targetJoinKey;
    }

    /**
     * @param array<string, mixed> $options Options passed to the association constructor.
     */
    protected function _options(array $options): void
    {
        $this->sourceJoinKey = $options['sourceJoinKey'] ?? [];
        $this->targetJoinKey = $options['targetJoinKey'] ?? [];
    }
}
