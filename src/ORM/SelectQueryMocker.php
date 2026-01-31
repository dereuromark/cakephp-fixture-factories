<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\ORM;

use Cake\ORM\Query\QueryFactory;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Factory\BaseFactory;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;

/**
 * A class to mock select queries result set for a given table
 */
class SelectQueryMocker
{
    /**
     * @param \Cake\TestSuite\TestCase $testCase test case should extend CakePHP's Test Case
     * @param \CakephpFixtureFactories\Factory\BaseFactory $factory fixture factory which non persisted entities will be returned by the select query
     * @param string|null $alias The model to get a mock for.
     * @param array<string> $methods The list of methods to mock
     * @param array<string, mixed> $options The config data for the mock's constructor.
     *
     * @return \Cake\ORM\Table|\PHPUnit\Framework\MockObject\MockObject
     */
    public static function mock(
        TestCase $testCase,
        BaseFactory $factory,
        ?string $alias = null,
        array $methods = [],
        array $options = [],
    ): Table|MockObject {
        $alias = $alias ?? $factory->getTable()->getAlias();
        $resultSet = $factory->getResultSet();

        // Use reflection to access the protected getMockBuilder method
        $reflection = new ReflectionMethod($testCase, 'getMockBuilder');

        /** @var \PHPUnit\Framework\MockObject\MockBuilder<\Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>> $selectQueryMockBuilder */
        $selectQueryMockBuilder = $reflection->invoke($testCase, SelectQuery::class);
        $selectQueryMocked = $selectQueryMockBuilder
            ->setConstructorArgs([$factory->getTable()])
            ->onlyMethods(['count', 'all'])
            ->getMock();
        $selectQueryMocked
            ->method('count')
            ->willReturn($resultSet->count());
        $selectQueryMocked
            ->method('all')
            ->willReturn($resultSet);

        /** @var \PHPUnit\Framework\MockObject\MockBuilder<\Cake\ORM\Query\QueryFactory> $queryFactoryMockBuilder */
        $queryFactoryMockBuilder = $reflection->invoke($testCase, QueryFactory::class);
        $queryFactoryMocked = $queryFactoryMockBuilder
            ->onlyMethods(['select'])
            ->getMock();
        $queryFactoryMocked
            ->method('select')
            ->willReturn($selectQueryMocked);

        $options['queryFactory'] = $queryFactoryMocked;

        // Use reflection to access the protected getMockForModel method
        $getMockForModelReflection = new ReflectionMethod($testCase, 'getMockForModel');

        return $getMockForModelReflection->invoke($testCase, $alias, $methods, $options);
    }
}
