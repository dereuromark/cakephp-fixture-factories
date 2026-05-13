<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\Scenario;

use CakephpFixtureFactories\Scenario\Story;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;

/**
 * Test scenario for `Story` — seeds named pools of countries and cities so
 * tests can `getRandom('countries')` / `getRandomSet('cities', $n)` to
 * compose follow-up factory builds against them.
 */
class BlogStory extends Story
{
    protected function build(): void
    {
        $countries = CountryFactory::new()->count(3)->saveMany();
        $this->addToPool('countries', $countries);

        // Build 5 cities all anchored to the first country so we get a
        // deterministic, dependency-free pool for tests to sample from.
        $cities = CityFactory::new()
            ->count(5)
            ->forCountries()
            ->recycle($countries[0])
            ->saveMany();
        $this->addToPool('cities', $cities);
    }
}
