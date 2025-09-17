<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\FactoryAwareTrait;

class FactoryAwareTraitUnitTest extends TestCase
{
    use FactoryAwareTrait;

    public static function getFactoryNamespaceData(): array
    {
        return [
            [null, 'TestApp\Test\Factory'],
            ['FooPlugin', 'FooPlugin\Test\Factory'],
            ['FooCorp/BarPlugin', 'FooCorp\BarPlugin\Test\Factory'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('getFactoryNamespaceData')]
    public function testGetFactoryNamespace(?string $plugin, string $expected): void
    {
        $this->assertEquals($expected, $this->getFactoryNamespace($plugin));
    }

    public static function getFactoryClassNameData(): array
    {
        return [
            ['Apples', 'TestApp\Test\Factory\AppleFactory'],
            ['FooPlugin.Apples', 'FooPlugin\Test\Factory\AppleFactory'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('getFactoryClassNameData')]
    public function testGetFactoryClassName(string $name, string $expected): void
    {
        $this->assertEquals($expected, $this->getFactoryClassName($name));
    }

    public static function getFactoryNameData(): array
    {
        return [
            ['Apples', 'AppleFactory', 'AppleFactory.php'],
            ['apples', 'AppleFactory', 'AppleFactory.php'],
            ['Apple', 'AppleFactory', 'AppleFactory.php'],
            ['apple', 'AppleFactory', 'AppleFactory.php'],
            ['pineApples', 'PineAppleFactory', 'PineAppleFactory.php'],
            ['PineApples', 'PineAppleFactory', 'PineAppleFactory.php'],
            ['pine_apples', 'PineAppleFactory', 'PineAppleFactory.php'],
            ['pine_apple', 'PineAppleFactory', 'PineAppleFactory.php'],
            ['Fruits/PineApple', 'Fruits\\PineAppleFactory', 'Fruits' . DIRECTORY_SEPARATOR . 'PineAppleFactory.php'],
            ['Food/Fruits/PineApple', 'Food\\Fruits\\PineAppleFactory', 'Food' . DIRECTORY_SEPARATOR . 'Fruits' . DIRECTORY_SEPARATOR . 'PineAppleFactory.php'],
            ['Table\Apples', 'AppleFactory', 'AppleFactory.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('getFactoryNameData')]
    public function testGetFactoryNameFromModelName(string $name, string $factoryName, string $factoryFileName): void
    {
        $this->assertEquals($factoryName, $this->getFactoryNameFromModelName($name));
        $this->assertEquals($factoryFileName, $this->getFactoryFileName($name));
    }
}
