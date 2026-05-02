<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.3.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\TestCase\Scenario;

use Cake\Core\Configure;
use Cake\ORM\Query\SelectQuery;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\FixtureScenarioException;
use CakephpFixtureFactories\Scenario\ScenarioAwareTrait;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Scenario\NAustralianAuthorsScenario;
use CakephpFixtureFactories\Test\Scenario\SubFolder\SubFolderScenario;
use CakephpFixtureFactories\Test\Scenario\TenAustralianAuthorsScenario;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Model\Entity\Author;

class FixtureScenarioTest extends TestCase
{
    use TruncateDirtyTables;
    use ScenarioAwareTrait;

    public static function setUpBeforeClass(): void
    {
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
    }

    public static function tearDownAfterClass(): void
    {
        Configure::delete('FixtureFactories.testFixtureNamespace');
    }

    public static function scenarioNames(): array
    {
        return [
            ['NAustralianAuthors', 3],
            [NAustralianAuthorsScenario::class, 5],
            ['TenAustralianAuthors', 10],
            [TenAustralianAuthorsScenario::class, 10],
            ['SubFolder/SubFolder', 0],
            [SubFolderScenario::class, 0],
        ];
    }

    #[DataProvider('scenarioNames')]
    public function testLoadScenario(mixed $scenario, int $expectedAuthors): void
    {
        /** @var array<\TestApp\Model\Entity\Author> $authors */
        $authors = $this->loadFixtureScenario($scenario, $expectedAuthors) ?? [];
        $this->assertSame($expectedAuthors, $this->countAustralianAuthors());
        foreach ($authors as $author) {
            $this->assertInstanceOf(Author::class, $author);
            $this->assertSame(
                NAustralianAuthorsScenario::COUNTRY_NAME,
                $author->address->city->country->name,
            );
        }
    }

    /**
     * Throw an exception because this is not implementing the FixtureScenarioInterface
     */
    public function testLoadScenarioException(): void
    {
        $this->expectException(FixtureScenarioException::class);
        $this->loadFixtureScenario(self::class);
    }

    /**
     * Regression: scenario namespace must strip the literal `Factory` suffix
     * via substring, not via `trim()` character-mask. For a configured
     * factory namespace whose char immediately before `Factory` is also a
     * member of the mask `{F, a, c, t, o, r, y}` (e.g. `Custom\TestFactory`,
     * where the trailing `t` of `Test` would also be eaten), the buggy
     * `trim()` produced an invalid scenario class name (`Custom\TesScenario`
     * instead of `Custom\TestScenario`).
     */
    public function testLoadScenarioNamespaceStripsFactorySuffixLiterally(): void
    {
        $original = Configure::read('FixtureFactories.testFixtureNamespace');
        Configure::write('FixtureFactories.testFixtureNamespace', 'Custom\TestFactory');

        try {
            $this->loadFixtureScenario('NonExistentScenario');
            $this->fail('Expected FixtureScenarioException to be thrown');
        } catch (FixtureScenarioException $e) {
            $this->assertStringContainsString(
                'Custom\TestScenario\NonExistentScenarioScenario',
                $e->getMessage(),
            );
            $this->assertStringNotContainsString(
                'Custom\TesScenario',
                $e->getMessage(),
            );
        } finally {
            if ($original === null) {
                Configure::delete('FixtureFactories.testFixtureNamespace');
            } else {
                Configure::write('FixtureFactories.testFixtureNamespace', $original);
            }
        }
    }

    private function countAustralianAuthors(): int
    {
        return AuthorFactory::find()
            ->innerJoinWith('Address.City.Countries', function (SelectQuery $q) {
                return $q->where(['Countries.name' => NAustralianAuthorsScenario::COUNTRY_NAME]);
            })
            ->count();
    }
}
