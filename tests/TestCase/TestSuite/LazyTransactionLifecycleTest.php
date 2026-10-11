<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\CityFactory;
use CakephpFixtureFactories\TestSuite\FactoryTransactionStrategy;
use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;
use CakephpFixtureFactories\TestSuite\LazyTransactionTrait;

class LazyTransactionLifecycleTest extends TestCase
{
    use FactoryTransactionTrait;
    use LazyTransactionTrait;

    public function testHookReleasesTransactionAfterCakeSetup(): void
    {
        $connection = CityFactory::new()->getTable()->getConnection();
        $this->assertInstanceOf(FactoryTransactionStrategy::class, FactoryTransactionStrategy::getActiveInstance());
        $this->assertFalse($connection->inTransaction());

        CityFactory::new(['name' => 'Lifecycle'])->save();

        $this->assertTrue($connection->inTransaction());
    }
}
