<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\Sequence;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

/**
 * `BaseFactory::sequence()` callables receive a single `Sequence` context
 * object exposing `$index`, `$position`, `$total`, `isFirst()`, `isLast()`,
 * plus the in-context `factory` / `generator` for the rare callable that
 * needs them but doesn't have them in `use(...)` scope.
 *
 * Greenfield single-arg shape — no legacy positional-arg signature is
 * supported.
 */
class BaseFactorySequenceObjectTest extends TestCase
{
    use TruncateDirtyTables;

    public function testSequenceCallableReceivesSequenceObject(): void
    {
        $seen = [];

        CountryFactory::new()
            ->count(3)
            ->sequence(function (Sequence $s) use (&$seen) {
                $seen[] = [
                    'index' => $s->index,
                    'position' => $s->position,
                    'total' => $s->total,
                    'isFirst' => $s->isFirst(),
                    'isLast' => $s->isLast(),
                ];

                return ['name' => 'Row' . $s->position];
            })
            ->saveMany();

        $this->assertSame(
            [
                ['index' => 0, 'position' => 1, 'total' => 3, 'isFirst' => true, 'isLast' => false],
                ['index' => 1, 'position' => 2, 'total' => 3, 'isFirst' => false, 'isLast' => false],
                ['index' => 2, 'position' => 3, 'total' => 3, 'isFirst' => false, 'isLast' => true],
            ],
            $seen,
        );
    }

    public function testSequenceObjectExposesFactoryAndGenerator(): void
    {
        // For callables that don't have $factory / $generator in scope via
        // `use(...)`, the Sequence object surfaces them directly.
        $factoryClass = null;
        $generatorClass = null;

        CountryFactory::new()
            ->count(1)
            ->sequence(function (Sequence $s) use (&$factoryClass, &$generatorClass) {
                $factoryClass = $s->factory::class;
                $generatorClass = $s->generator::class;

                return [];
            })
            ->saveMany();

        $this->assertSame(CountryFactory::class, $factoryClass);
        $this->assertNotNull($generatorClass);
    }

    public function testIsFirstAndIsLastBothTrueForSingleCount(): void
    {
        $sawFirst = false;
        $sawLast = false;

        CountryFactory::new()
            ->count(1)
            ->sequence(function (Sequence $s) use (&$sawFirst, &$sawLast) {
                $sawFirst = $s->isFirst();
                $sawLast = $s->isLast();

                return [];
            })
            ->saveMany();

        $this->assertTrue($sawFirst);
        $this->assertTrue($sawLast);
    }

    public function testSequenceWraparoundReportsOuterIndexNotStateSlot(): void
    {
        // When `count(N)` exceeds the number of provided sequence states, the
        // states cycle. The Sequence index reflects the *outer* build
        // iteration, not the modulo'd state slot.
        $seenIndexes = [];
        $seenPositions = [];

        CountryFactory::new()
            ->count(5)
            ->sequence(
                function (Sequence $s) use (&$seenIndexes, &$seenPositions) {
                    $seenIndexes[] = $s->index;
                    $seenPositions[] = $s->position;

                    return ['name' => 'Country' . $s->index];
                },
                function (Sequence $s) use (&$seenIndexes, &$seenPositions) {
                    $seenIndexes[] = $s->index;
                    $seenPositions[] = $s->position;

                    return ['name' => 'Country' . $s->index];
                },
            )
            ->saveMany();

        $this->assertSame([0, 1, 2, 3, 4], $seenIndexes);
        $this->assertSame([1, 2, 3, 4, 5], $seenPositions);
    }

    public function testTotalReflectsCountValue(): void
    {
        $seenTotals = [];

        CountryFactory::new()
            ->count(4)
            ->sequence(function (Sequence $s) use (&$seenTotals) {
                $seenTotals[] = $s->total;

                return [];
            })
            ->saveMany();

        $this->assertSame([4, 4, 4, 4], $seenTotals);
    }
}
