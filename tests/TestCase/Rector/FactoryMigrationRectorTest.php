<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Rector;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;
use Throwable;

class FactoryMigrationRectorTest extends AbstractRectorTestCase
{
    /**
     * @return \Iterator<string>
     */
    public static function provideData(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    #[DataProvider('provideData')]
    public function testRectorRules(string $fixtureFilePath): void
    {
        try {
            $this->doTestFile($fixtureFilePath);
        } catch (Throwable $exception) {
            if ($this->isKnownTokenizerCompatibilityIssue($exception)) {
                $this->markTestSkipped($exception->getMessage());
            }

            throw $exception;
        }
    }

    public function provideConfigFilePath(): string
    {
        return dirname(__DIR__, 3) . '/rector.php';
    }

    private function isKnownTokenizerCompatibilityIssue(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Token T_PUBLIC_SET has ID of type string')
            || str_contains($message, 'Undefined constant "T_PROPERTY_C"');
    }
}
