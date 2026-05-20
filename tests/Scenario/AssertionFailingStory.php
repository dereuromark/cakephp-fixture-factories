<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\Scenario;

use CakephpFixtureFactories\Scenario\Story;
use PHPUnit\Framework\Assert;

/**
 * Fixture scenario that throws PHPUnit's `AssertionFailedError` from its
 * build phase, exercising the trait's framework-exception pass-through.
 */
class AssertionFailingStory extends Story
{
    protected function build(): void
    {
        Assert::fail('intentional fail from scenario build');
    }
}
