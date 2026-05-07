<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Codegen;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Codegen\DefaultDataGuesser;

class DefaultDataGuesserTest extends TestCase
{
    private DefaultDataGuesser $guesser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guesser = new DefaultDataGuesser();
    }

    protected function tearDown(): void
    {
        Configure::delete('FixtureFactories.defaultDataMap');
        Configure::delete('FixtureFactories.columnPatterns');
        parent::tearDown();
    }

    public function testGuessShortStringEmitsLexify(): void
    {
        $expression = $this->guesser->guess('code', 'Articles', [
            'type' => 'string',
            'null' => false,
            'default' => null,
            'length' => 3,
        ]);

        $this->assertSame('$generator->lexify("???")', $expression);
    }

    public function testGuessLongStringFallsBackToText(): void
    {
        $expression = $this->guesser->guess('description', 'Articles', [
            'type' => 'string',
            'null' => false,
            'default' => null,
            'length' => 500,
        ]);

        $this->assertSame('$generator->words()', $expression, 'description is mapped explicitly so the length fallback should not run');
    }

    public function testCountriesNameSpecialCase(): void
    {
        $expression = $this->guesser->guess('name', 'Countries', [
            'type' => 'string',
            'null' => false,
            'default' => null,
            'length' => 100,
        ]);

        $this->assertSame('$generator->country()', $expression);
    }

    public function testCustomColumnPatternsWinOverDefaults(): void
    {
        Configure::write('FixtureFactories.columnPatterns', [
            '/_color$/' => 'hexColor()',
        ]);

        $expression = $this->guesser->guess('background_color', 'Themes', [
            'type' => 'string',
            'null' => false,
            'default' => null,
            'length' => 7,
        ]);

        $this->assertSame('$generator->hexColor()', $expression);
    }

    public function testDecimalUsesScaleAndPriceHeuristic(): void
    {
        $expression = $this->guesser->guess('price', 'Products', [
            'type' => 'decimal',
            'null' => false,
            'default' => null,
            'precision' => 10,
            'scale' => 2,
        ]);

        $this->assertSame('$generator->randomFloat(2, 0, 1000)', $expression);
    }

    public function testJsonEmitsLiteralArrayNotJsonEncode(): void
    {
        $expression = $this->guesser->guess('payload', 'Events', [
            'type' => 'json',
            'null' => false,
            'default' => null,
        ]);

        $this->assertSame('["key" => $generator->word(), "value" => $generator->randomNumber()]', $expression);
    }

    public function testCreatedAtSuffixUsesOptionalDateTime(): void
    {
        $expression = $this->guesser->guess('archived_at', 'Articles', [
            'type' => 'datetime',
            'null' => false,
            'default' => null,
        ]);

        $this->assertSame('$generator->optional(0.7)->dateTime()', $expression);
    }

    public function testUserMapMergesOnTopOfDefaults(): void
    {
        Configure::write('FixtureFactories.defaultDataMap', [
            'string' => [
                'sku' => 'ean13',
            ],
        ]);

        $expression = $this->guesser->guess('sku', 'Products', [
            'type' => 'string',
            'null' => false,
            'default' => null,
            'length' => 13,
        ]);

        $this->assertSame('$generator->ean13()', $expression);
    }

    public function testUserMapAcceptsFullGeneratorExpression(): void
    {
        Configure::write('FixtureFactories.defaultDataMap', [
            'string' => [
                'phone' => '$generator->phoneNumber()',
            ],
        ]);

        $expression = $this->guesser->guess('phone', 'Users', [
            'type' => 'string',
            'null' => false,
            'default' => null,
            'length' => 20,
        ]);

        $this->assertSame('$generator->phoneNumber()', $expression);
    }

    public function testUnknownColumnTypeReturnsNull(): void
    {
        $expression = $this->guesser->guess('payload', 'Events', [
            'type' => 'binary',
            'null' => false,
            'default' => null,
        ]);

        $this->assertNull($expression);
    }

    public function testColumnPatternsAcceptFullGeneratorExpression(): void
    {
        Configure::write('FixtureFactories.columnPatterns', [
            '/^phone/' => '$generator->phoneNumber()',
        ]);

        $expression = $this->guesser->guess('phone_home', 'Users', [
            'type' => 'string',
            'null' => false,
            'default' => null,
            'length' => 20,
        ]);

        $this->assertSame('$generator->phoneNumber()', $expression);
    }

    public function testFloatUserMapAcceptsFullGeneratorExpression(): void
    {
        Configure::write('FixtureFactories.defaultDataMap', [
            'float' => [
                'price' => '$generator->randomFloat(2, 1, 9)',
            ],
        ]);

        $expression = $this->guesser->guess('price', 'Products', [
            'type' => 'float',
            'null' => false,
            'default' => null,
        ]);

        $this->assertSame('$generator->randomFloat(2, 1, 9)', $expression);
    }

    public function testFloatUserMapAcceptsShorthandMethodName(): void
    {
        Configure::write('FixtureFactories.defaultDataMap', [
            'float' => [
                'price' => 'randomFloat',
            ],
        ]);

        $expression = $this->guesser->guess('price', 'Products', [
            'type' => 'float',
            'null' => false,
            'default' => null,
        ]);

        $this->assertSame('$generator->randomFloat()', $expression);
    }
}
