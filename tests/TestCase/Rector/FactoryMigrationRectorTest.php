<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Rector;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

class FactoryMigrationRectorTest extends AbstractRectorTestCase
{
    /**
     * @return Iterator<string>
     */
    public static function provideData(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    #[DataProvider('provideData')]
    public function testRectorRules(string $fixtureFilePath): void
    {
        $this->doTestFile($fixtureFilePath);
    }

    public function provideConfigFilePath(): string
    {
        return dirname(__DIR__, 3) . '/rector.php';
    }
}
