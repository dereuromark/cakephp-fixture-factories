<?php

declare(strict_types=1);

namespace CakephpFixtureFactories\Test\TestCase\TestSuite;

use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use CakephpFixtureFactories\TestSuite\FactoryTransactionTrait;
use CakephpFixtureFactories\TestSuite\LazyTransactionTrait;

class LazyTransactionSeededLifecycleTest extends TestCase
{
    use FactoryTransactionTrait;
    use LazyTransactionTrait;

    private int $seedId;

    protected function setUp(): void
    {
        parent::setUp();

        $country = CountryFactory::new(['name' => 'Seeded lifecycle'])->save();
        $this->seedId = $country->id;
    }

    protected function tearDown(): void
    {
        $table = CountryFactory::table();
        parent::tearDown();

        $this->assertSame(0, $table->find()->where(['id' => $this->seedId])->count());
    }

    public function testSetUpSeedSurvivesUntilTestBody(): void
    {
        $table = CountryFactory::table();
        $this->assertTrue($table->getConnection()->inTransaction());
        $this->assertSame(1, $table->find()->where(['id' => $this->seedId])->count());
    }
}
