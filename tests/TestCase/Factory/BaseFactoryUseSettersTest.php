<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 2.7.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use TypeError;

class BaseFactoryUseSettersTest extends TestCase
{
    public function testNonStringField(): void
    {
        $this->expectException(TypeError::class);
        AuthorFactory::new()->skipSetterFor(0);
    }

    public function testSettersAreDefinedOnOneAuthor(): void
    {
        $value = 'Foo';
        $author = AuthorFactory::new([
            'field_with_setter_1' => $value,
            'field_with_setter_2' => $value,
            'field_with_setter_3' => $value,
        ])->skipSetterFor([])->build();

        for ($i = 1; $i < 4; $i++) {
            $this->assertSame($author->prependPrefixToField($value), $author->get("field_with_setter_$i"));
        }
    }

    public function testSettersAreDefinedOnTwoAuthors(): void
    {
        $value = 'Foo';
        $authors = AuthorFactory::new([
            'field_with_setter_1' => $value,
            'field_with_setter_2' => $value,
            'field_with_setter_3' => $value,
        ], 2)->skipSetterFor([])->buildMany();

        foreach ($authors as $author) {
            for ($i = 1; $i < 4; $i++) {
                $this->assertSame($author->prependPrefixToField($value), $author->get("field_with_setter_$i"));
            }
        }
    }

    public function testSettersAreDefinedOnAssociatedAuthor(): void
    {
        $value = 'Foo';
        $authorFactory = AuthorFactory::new(4)
            ->state([
                'field_with_setter_1' => $value,
                'field_with_setter_2' => $value,
                'field_with_setter_3' => $value,
            ])
            ->skipSetterFor([]);
        $authors = ArticleFactory::new()->with('Authors', $authorFactory)->build()->authors;

        foreach ($authors as $author) {
            for ($i = 1; $i < 4; $i++) {
                $this->assertSame($author->prependPrefixToField($value), $author->get("field_with_setter_$i"));
            }
        }
    }

    public function testSetterIsSkippedForDefaultFields(): void
    {
        $value = 'Foo';
        $author = AuthorFactory::new([
            'field_with_setter_1' => $value,
            'field_with_setter_2' => $value,
            'field_with_setter_3' => $value,
        ])->build();

        $this->assertSame($value, $author->get('field_with_setter_1'));
        $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_2'));
        $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_3'));
    }

    public function testSettersAreSkippedDefinedOnTwoAuthors(): void
    {
        $value = 'Foo';
        $authors = AuthorFactory::new([
            'field_with_setter_1' => $value,
            'field_with_setter_2' => $value,
            'field_with_setter_3' => $value,
        ], 2)->skipSetterFor('field_with_setter_2')->buildMany();

        foreach ($authors as $author) {
            $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_1'));
            $this->assertSame($value, $author->get('field_with_setter_2'));
            $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_3'));
        }
    }

    public function testSettersAreSkippedDefinedOnTwoAuthorsWithMerge(): void
    {
        $value = 'Foo';
        $authors = AuthorFactory::new([
            'field_with_setter_1' => $value,
            'field_with_setter_2' => $value,
            'field_with_setter_3' => $value,
        ], 2)->skipSetterFor('field_with_setter_2', true)->buildMany();

        foreach ($authors as $author) {
            $this->assertSame($value, $author->get('field_with_setter_1'));
            $this->assertSame($value, $author->get('field_with_setter_2'));
            $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_3'));
        }
    }

    public function testSettersAreSkippedOnAssociatedAuthor(): void
    {
        $value = 'Foo';
        $authorFactory = AuthorFactory::new(4)
            ->state([
                'field_with_setter_1' => $value,
                'field_with_setter_2' => $value,
                'field_with_setter_3' => $value,
            ])
            ->skipSetterFor(['field_with_setter_2', 'field_with_setter_3']);
        $authors = ArticleFactory::new()->with('Authors', $authorFactory)->build()->authors;

        foreach ($authors as $author) {
            $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_1'));
            $this->assertSame($value, $author->get('field_with_setter_2'));
            $this->assertSame($value, $author->get('field_with_setter_3'));
        }
    }
}
