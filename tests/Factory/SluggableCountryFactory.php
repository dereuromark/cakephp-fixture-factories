<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\Factory;

class SluggableCountryFactory extends CountryFactory
{
    protected function configure(): static
    {
        return $this->listeningToBehaviors('Sluggable');
    }
}
