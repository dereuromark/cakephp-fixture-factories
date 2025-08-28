<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\TestSuite\TestCase;
use TestApp\Test\Factory\ArticleFactory;

/**
 * Test case to reproduce issue #226: Factory::make ignores null values
 *
 * @see https://github.com/vierge-noire/cakephp-fixture-factories/issues/226
 */
class Issue226Test extends TestCase
{
    /**
     * Test that null values passed to make() are properly preserved
     * and getOriginal() returns null after modifying the property
     *
     * @return void
     */
    public function testMakeWithNullValuePreservesOriginal(): void
    {
        // Create a factory with null value for 'title'
        $article = ArticleFactory::make(['title' => null])->getEntity();

        // Store the original value before modification
        $originalBeforeChange = $article->getOriginal('title');

        // Change the value
        $article->title = 'New Title';

        // Get the original value after modification
        $originalAfterChange = $article->getOriginal('title');

        // The original value should be null, not 'New Title'
        $this->assertNull($originalAfterChange, 'Original value should be null after modification');
        $this->assertNull($originalBeforeChange, 'Original value should be null before modification');
        $this->assertEquals('New Title', $article->title, 'Current value should be "New Title"');
    }

    /**
     * Test with a field that doesn't have a default value in the factory
     *
     * @return void
     */
    public function testMakeWithNullValueForNonDefaultField(): void
    {
        // Create a factory with null value for 'body' (which has no default in factory)
        $article = ArticleFactory::make(['body' => null])->getEntity();

        // Store the original value before modification
        $originalBeforeChange = $article->getOriginal('body');

        // Change the value
        $article->body = 'New Body Content';

        // Get the original value after modification
        $originalAfterChange = $article->getOriginal('body');

        // The original value should be null
        $this->assertNull($originalAfterChange, 'Original value should be null after modification for non-default field');
        $this->assertNull($originalBeforeChange, 'Original value should be null before modification for non-default field');
        $this->assertEquals('New Body Content', $article->body, 'Current value should be "New Body Content"');
    }

    /**
     * Test with persist() method as mentioned in the issue
     *
     * @return void
     */
    public function testPersistWithNullValuePreservesOriginal(): void
    {
        // Create and persist a factory with null value for 'body' (nullable field)
        $article = ArticleFactory::make(['body' => null])->persist();

        // Store the original value before modification
        $originalBeforeChange = $article->getOriginal('body');

        // Change the value
        $article->body = 'Updated Body';

        // Get the original value after modification
        $originalAfterChange = $article->getOriginal('body');

        // The original value should be null
        $this->assertNull($originalAfterChange, 'Original value should be null after modification with persist()');
        $this->assertNull($originalBeforeChange, 'Original value should be null before modification with persist()');
        $this->assertEquals('Updated Body', $article->body, 'Current value should be "Updated Body"');
    }

    /**
     * Control test: Ensure that non-null values work correctly
     *
     * @return void
     */
    public function testMakeWithNonNullValuePreservesOriginal(): void
    {
        // Create a factory with a non-null value
        $article = ArticleFactory::make(['title' => 'Original Title'])->getEntity();

        // Change the value
        $article->title = 'Modified Title';

        // Get the original value after modification
        $originalAfterChange = $article->getOriginal('title');

        // The original value should be 'Original Title'
        $this->assertEquals('Original Title', $originalAfterChange, 'Original non-null value should be preserved');
        $this->assertEquals('Modified Title', $article->title, 'Current value should be "Modified Title"');
    }
}
