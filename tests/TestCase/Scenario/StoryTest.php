<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\TestCase\Scenario;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\FixtureScenarioException;
use CakephpFixtureFactories\Scenario\ScenarioAwareTrait;
use CakephpFixtureFactories\Scenario\Story;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\Test\Scenario\AssertionFailingStory;
use CakephpFixtureFactories\Test\Scenario\BlogStory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use PHPUnit\Framework\AssertionFailedError;
use TestApp\Model\Entity\Country;

/**
 * Tests for the `Story` abstract class — a Foundry-style scenario with named
 * entity pools that tests can sample from after loading.
 */
class StoryTest extends TestCase
{
    use ScenarioAwareTrait;
    use TruncateDirtyTables;

    public static function setUpBeforeClass(): void
    {
        // Point short-name scenario resolution at this package's test factory
        // namespace so `loadFixtureScenario('BlogStory')` finds
        // `CakephpFixtureFactories\Test\Scenario\BlogStory`.
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
    }

    public static function tearDownAfterClass(): void
    {
        Configure::delete('FixtureFactories.testFixtureNamespace');
    }

    public function testLoadFixtureScenarioReturnsStoryInstance(): void
    {
        $story = $this->loadFixtureScenario(BlogStory::class);

        $this->assertInstanceOf(Story::class, $story);
        $this->assertInstanceOf(BlogStory::class, $story);
    }

    public function testStoryBuildsSeedsTheTables(): void
    {
        $this->loadFixtureScenario(BlogStory::class);

        // BlogStory seeds 3 countries, then 5 cities that all recycle the
        // first country — so the total country count stays at 3.
        $this->assertSame(3, CountryFactory::query()->count());
        $this->assertSame(5, CityFactory::query()->count());
    }

    public function testGetPoolReturnsAllEntitiesInPool(): void
    {
        /** @var \CakephpFixtureFactories\Test\Scenario\BlogStory $story */
        $story = $this->loadFixtureScenario(BlogStory::class);

        $countries = $story->getPool('countries');
        $this->assertCount(3, $countries);
        foreach ($countries as $country) {
            $this->assertInstanceOf(Country::class, $country);
        }
    }

    public function testGetRandomReturnsOneEntityFromPool(): void
    {
        /** @var \CakephpFixtureFactories\Test\Scenario\BlogStory $story */
        $story = $this->loadFixtureScenario(BlogStory::class);

        $country = $story->getRandom('countries');
        $this->assertInstanceOf(Country::class, $country);
        $this->assertContains($country, $story->getPool('countries'));
    }

    public function testGetRandomSetReturnsRequestedNumberOfDistinctEntities(): void
    {
        /** @var \CakephpFixtureFactories\Test\Scenario\BlogStory $story */
        $story = $this->loadFixtureScenario(BlogStory::class);

        $set = $story->getRandomSet('cities', 3);
        $this->assertCount(3, $set);

        // All entries must be distinct (no repeats within one draw).
        $ids = array_map(static fn ($e) => $e->id, $set);
        $this->assertCount(3, array_unique($ids), 'getRandomSet must return distinct entities.');

        // Each entry must be from the pool.
        $poolIds = array_map(static fn ($e) => $e->id, $story->getPool('cities'));
        foreach ($ids as $id) {
            $this->assertContains($id, $poolIds);
        }
    }

    public function testGetRandomThrowsOnUnknownPool(): void
    {
        /** @var \CakephpFixtureFactories\Test\Scenario\BlogStory $story */
        $story = $this->loadFixtureScenario(BlogStory::class);

        $this->expectException(FixtureScenarioException::class);
        $this->expectExceptionMessageMatches('/pool .*unknown.* does not exist/i');

        $story->getRandom('unknown');
    }

    public function testGetRandomSetThrowsWhenRequestingMoreThanPoolSize(): void
    {
        /** @var \CakephpFixtureFactories\Test\Scenario\BlogStory $story */
        $story = $this->loadFixtureScenario(BlogStory::class);

        $this->expectException(FixtureScenarioException::class);
        $this->expectExceptionMessageMatches('/cannot draw 99.*countries.*holds 3/i');

        $story->getRandomSet('countries', 99);
    }

    public function testAddToPoolAcceptsSingleEntity(): void
    {
        // Anonymous Story to test addToPool's single-entity overload.
        $story = new class () extends Story {
            protected function build(): void
            {
                $only = CountryFactory::new(['name' => 'Solo'])->save();
                $this->addToPool('one', $only);
            }
        };
        /** @var \CakephpFixtureFactories\Scenario\Story $story */
        $story = $story->load();

        $this->assertCount(1, $story->getPool('one'));
        $this->assertSame('Solo', $story->getPool('one')[0]->name);
    }

    public function testAddToPoolAppendsAcrossMultipleCalls(): void
    {
        $story = new class () extends Story {
            protected function build(): void
            {
                $this->addToPool('countries', CountryFactory::new()->count(2)->saveMany());
                $this->addToPool('countries', CountryFactory::new()->count(3)->saveMany());
            }
        };
        /** @var \CakephpFixtureFactories\Scenario\Story $story */
        $story = $story->load();

        $this->assertCount(5, $story->getPool('countries'));
    }

    public function testShortNameLoadResolvesStoryClass(): void
    {
        // Short-name loading must resolve *Story classes verbatim,
        // not by appending the `Scenario` suffix.
        $story = $this->loadFixtureScenario('BlogStory');

        $this->assertInstanceOf(BlogStory::class, $story);
    }

    public function testStorySupportsParameterizedBuild(): void
    {
        // Subclasses can declare build() with their own params; load() forwards
        // the scenario args unchanged. This is the codex-flagged use case.
        $story = new class () extends Story {
            protected function build(int $n, string $name): void
            {
                $this->addToPool(
                    'countries',
                    CountryFactory::new(['name' => $name])->count($n)->saveMany(),
                );
            }
        };
        /** @var \CakephpFixtureFactories\Scenario\Story $story */
        $story = $story->load(4, 'Repeated');

        $this->assertCount(4, $story->getPool('countries'));
        foreach ($story->getPool('countries') as $country) {
            $this->assertSame('Repeated', $country->get('name'));
        }
    }

    public function testStoryWithoutBuildMethodThrows(): void
    {
        $story = new class () extends Story {
            // No build() declared — Story::load() must complain clearly.
        };

        $this->expectException(FixtureScenarioException::class);
        $this->expectExceptionMessageMatches('/does not define a `build/');

        $story->load();
    }

    public function testGetRandomSetValidatesPoolEvenForZeroCount(): void
    {
        /** @var \CakephpFixtureFactories\Test\Scenario\BlogStory $story */
        $story = $this->loadFixtureScenario(BlogStory::class);

        $this->expectException(FixtureScenarioException::class);
        $this->expectExceptionMessageMatches('/pool .*typo.* does not exist/i');

        $story->getRandomSet('typo', 0);
    }

    public function testGetRandomSetWithZeroReturnsEmptyArray(): void
    {
        /** @var \CakephpFixtureFactories\Test\Scenario\BlogStory $story */
        $story = $this->loadFixtureScenario(BlogStory::class);

        $this->assertSame([], $story->getRandomSet('countries', 0));
    }

    /**
     * A PHPUnit framework exception (here `AssertionFailedError`, raised
     * via `Assert::fail()` from inside the scenario's build) must reach
     * the runner *untouched* — the trait used to wrap every Throwable in
     * `FixtureScenarioException`, which made the test show as an error
     * instead of a failure and broke `expectException` / risky-test
     * handling. Pass-through is the contract.
     */
    public function testPhpUnitAssertionFailedErrorBubblesUntouched(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('intentional fail from scenario build');

        $this->loadFixtureScenario(AssertionFailingStory::class);
    }

    public function testAddToPoolRejectsEmptyPoolName(): void
    {
        // An empty pool name slips past PHP's `string` type but produces
        // a useless error on the read side (`guardPoolExists('')`). Refuse
        // it at write time with a clear message.
        $story = new class () extends Story {
            protected function build(): void
            {
                $this->addToPool('', CountryFactory::new()->save());
            }
        };

        $this->expectException(FixtureScenarioException::class);
        $this->expectExceptionMessageMatches('/addToPool.* requires a non-empty pool name/');

        $story->load();
    }
}
