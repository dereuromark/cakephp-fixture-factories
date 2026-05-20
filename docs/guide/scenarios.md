# Scenarios

You can create scenarios that will persist a multitude of test fixtures. This can be useful to seed your
test database with a reusable set of data.

Use the `CakephpFixtureFactories\Scenario\ScenarioAwareTrait`
in your test and load your scenario with the `loadFixtureScenario()` method. You can either provide the
fully qualified name of the scenario class, or place your scenarios under the `App\Test\Scenario` namespace.


Example:
```php
$authors = $this->loadFixtureScenario('NAustralianAuthors', 3);
```

The `N` prefix in the class name is a convention meaning "N of them" — the scenario takes a count argument and persists that many entities. The call above will persist 3 authors associated with the country Australia, as defined here:

```php

use CakephpFixtureFactories\Scenario\FixtureScenarioInterface;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use TestApp\Model\Entity\Author;

class NAustralianAuthorsScenario implements FixtureScenarioInterface
{
    const COUNTRY_NAME = 'Australia';

    /**
     * @param int $n the number of authors
     * @return array<Author>
     */
    public function load($n = 1, ...$args)
    {
        return AuthorFactory::new()->count($n)->fromCountry(self::COUNTRY_NAME)->saveMany();
    }
}

```

Scenarios must implement `CakephpFixtureFactories\Scenario\FixtureScenarioInterface`. Example test using a scenario:

```php

namespace CakephpFixtureFactories\Test\TestCase\Scenario;

use Cake\ORM\Query\SelectQuery;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Scenario\ScenarioAwareTrait;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Scenario\NAustralianAuthorsScenario;
use TestApp\Model\Entity\Author;

class FixtureScenarioTest extends TestCase
{
    use ScenarioAwareTrait;

    public function testLoadScenario()
    {
        /** @var Author[] $authors */
        $authors = $this->loadFixtureScenario(NAustralianAuthorsScenario::class, 3) ?? [];

        $this->assertSame(3, $this->countAustralianAuthors());

        foreach ($authors as $author) {
            $this->assertInstanceOf(Author::class, $author);
            $this->assertSame(
                NAustralianAuthorsScenario::COUNTRY_NAME,
                $author->address->city->country->name
            );
        }
    }

    private function countAustralianAuthors(): int
    {
        return AuthorFactory::query()
            ->innerJoinWith('Address.City.Country', function (SelectQuery $q) {
                return $q->where(['Country.name' => NAustralianAuthorsScenario::COUNTRY_NAME]);
            })
            ->count();
    }
}

```

## Story pattern: named entity pools

For scenarios that seed data and then need to be **sampled** from in the body of the test — "give me one random author from the 10 you just seeded" — extend the `Story` abstract class instead of implementing `FixtureScenarioInterface` directly. `Story` ships with a small pool API on top of the same loading machinery.

```php
use CakephpFixtureFactories\Scenario\Story;

class BlogStory extends Story
{
    protected function build(): void
    {
        $this->addToPool('authors',    UserFactory::new()->count(10)->saveMany());
        $this->addToPool('categories', CategoryFactory::new()->count(3)->saveMany());

        // Seed 50 articles randomly distributed across the pooled authors:
        ArticleFactory::new()
            ->count(50)
            ->afterBuild(fn ($article) => $article->set('author_id', $this->getRandom('authors')->id))
            ->saveMany();
    }
}
```

In the test, `loadFixtureScenario()` returns the `Story` instance so the same handles are available for assertions and follow-up factory builds:

```php
class FeedTest extends TestCase
{
    use ScenarioAwareTrait;

    public function testRandomArticleHasAPooledAuthor(): void
    {
        $story = $this->loadFixtureScenario(BlogStory::class);

        $author  = $story->getRandom('authors');                // one entity
        $threeOf = $story->getRandomSet('categories', 3);       // n distinct entities

        $this->assertContains($author, $story->getPool('authors'));
        $this->assertCount(3, $threeOf);
    }
}
```

### API

- `addToPool(string $pool, EntityInterface|array $entities)` — register one or more entities under a named pool; repeated calls append. Empty pool names are refused with a clear error (an empty key on the read side produced a useless message before).
- `getPool(string $pool): array<EntityInterface>` — return every entity in a pool.
- `getRandom(string $pool): EntityInterface` — uniform random pick from a pool.
- `getRandomSet(string $pool, int $count): array<EntityInterface>` — `$count` distinct entities drawn from a pool (raises if the pool holds fewer).

Unknown pool names raise `FixtureScenarioException` with a paste-ready list of the pools that *do* exist on the story.

> [!NOTE]
> `Story` `implements FixtureScenarioInterface`, so it works with `ScenarioAwareTrait::loadFixtureScenario(...)` exactly like plain scenarios. Existing scenarios that implement the interface directly keep working unchanged — `Story` is purely additive.
