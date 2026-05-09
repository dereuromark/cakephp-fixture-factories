<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\ORM\FactoryTableRegistry;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpTestSuiteLight\Fixture\TruncateDirtyTables;

/**
 * Regression coverage for issue #62.
 *
 * Failure mode: when a factory's source table is loaded under a hash-scoped
 * registry alias (e.g. `Authors__ff_<hash>`) and CakePHP's BelongsToMany
 * machinery later resolves the same logical table by its bare or
 * plugin-prefixed alias — the two cache entries diverge into separate Table
 * instances. CakePHP's `_generateJunctionAssociations` then sees a junction
 * `belongsTo($sAlias)` whose target was registered with one instance but
 * a different instance arriving from the inverse direction's target lookup,
 * producing:
 *
 *   The existing `<X>` association on `<XY>` is incompatible with the
 *   `<X>` association on `<Y>`
 *
 * The fix pins the bare and plugin-prefixed aliases of every factory-loaded
 * table to the same instance in `FactoryTableRegistry`, so all subsequent
 * lookups for the same logical table — by any of its aliases — return the
 * same Table object.
 */
class Issue62JunctionInstanceTest extends TestCase
{
    use TruncateDirtyTables;

    public static function setUpBeforeClass(): void
    {
        Configure::write('FixtureFactories.testFixtureNamespace', 'CakephpFixtureFactories\Test\Factory');
    }

    /**
     * After loading a factory's table, the bare alias must resolve to the
     * SAME instance as the scoped alias. This is the contract the fix
     * establishes — without it, downstream BTM lookups silently spawn a
     * parallel instance.
     */
    public function testFactoryAndBareAliasResolveToSameInstance(): void
    {
        $factoryTable = AuthorFactory::table();
        $bareLookup = FactoryTableRegistry::getTableLocator()->get('Authors');

        $this->assertSame(
            $factoryTable,
            $bareLookup,
            'AuthorFactory::table() and a bare-alias lookup must return the same Table instance.',
        );
    }

    /**
     * Trigger the exact junction conflict from #62: register a junction
     * `belongsTo($source)` whose target is the factory's scoped instance,
     * then force the inverse direction to look up the same logical source
     * by its bare alias and verify that lookup returns the same instance —
     * so the BTM equality check inside CakePHP's
     * `_generateJunctionAssociations` does not blow up.
     *
     * The `remove()` here simulates the state a real test suite ends up in
     * across PHPUnit test boundaries: cross-test pollution where parts of
     * the locator have been evicted while association references on
     * already-loaded tables still point at the original instances.
     */
    public function testInverseSaveAfterTargetEvictionDoesNotConflict(): void
    {
        $author = AuthorFactory::new()->with('Articles', 1)->save();
        $this->assertNotNull($author->id);

        $locator = FactoryTableRegistry::getTableLocator();
        $locator->remove('Authors');
        $locator->remove('ArticlesAuthors');

        // Without the fix this throws:
        //   "The existing `Authors` association on `ArticlesAuthors` is
        //    incompatible with the `Authors` association on `Articles`"
        $article = ArticleFactory::new()->with('Authors', 1)->save();
        $this->assertNotNull($article->id);
    }
}
