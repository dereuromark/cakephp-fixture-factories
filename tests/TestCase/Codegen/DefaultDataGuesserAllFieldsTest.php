<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 */

namespace CakephpFixtureFactories\Test\TestCase\Codegen;

use ArrayIterator;
use Cake\Database\Schema\TableSchema;
use Cake\ORM\AssociationCollection;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Codegen\DefaultDataGuesser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Tests for the "include optional columns" toggle on `DefaultDataGuesser`,
 * which backs the `bake fixture_factory --all-fields` flag.
 *
 * Default behavior (off): only NOT NULL columns without a DB default and
 * outside any foreign-key constraint are emitted. Toggle on: nullable
 * columns and columns with DB defaults are also emitted; FKs and PKs
 * remain skipped — per codex review feedback, since baked FKs would
 * undermine the association-aware `with()` / `for()` / `has()` APIs.
 */
#[AllowMockObjectsWithoutExpectations]
class DefaultDataGuesserAllFieldsTest extends TestCase
{
    public function testDefaultBehaviorSkipsNullableAndDefaultedColumns(): void
    {
        $table = $this->buildTable([
            'columns' => ['id', 'name', 'optional_note', 'status'],
            'primaryKey' => ['id'],
            'columnSchemas' => [
                'name' => ['type' => 'string', 'null' => false, 'default' => null, 'length' => 50],
                'optional_note' => ['type' => 'string', 'null' => true, 'default' => null, 'length' => 200],
                'status' => ['type' => 'string', 'null' => false, 'default' => 'active', 'length' => 20],
            ],
        ]);

        $defaults = (new DefaultDataGuesser())->guessFor($table);

        $this->assertArrayHasKey('name', $defaults);
        // Nullable column — skipped under the default rule.
        $this->assertArrayNotHasKey('optional_note', $defaults);
        // Has DB default — skipped under the default rule.
        $this->assertArrayNotHasKey('status', $defaults);
    }

    public function testAllFieldsIncludesNullableAndDefaultedColumns(): void
    {
        $table = $this->buildTable([
            'columns' => ['id', 'name', 'optional_note', 'status'],
            'primaryKey' => ['id'],
            'columnSchemas' => [
                'name' => ['type' => 'string', 'null' => false, 'default' => null, 'length' => 50],
                'optional_note' => ['type' => 'string', 'null' => true, 'default' => null, 'length' => 200],
                'status' => ['type' => 'string', 'null' => false, 'default' => 'active', 'length' => 20],
            ],
        ]);

        $defaults = (new DefaultDataGuesser())
            ->setIncludeOptional(true)
            ->guessFor($table);

        $this->assertArrayHasKey('name', $defaults);
        $this->assertArrayHasKey('optional_note', $defaults);
        $this->assertArrayHasKey('status', $defaults);
    }

    public function testAllFieldsStillSkipsPrimaryKeysAndForeignKeys(): void
    {
        // The proposal explicitly drops FKs even with --all-fields so the
        // baked factory stays association-aware (users still wire `with()`
        // / `for()` / `has()` chains instead of raw integer FK values).
        $table = $this->buildTable([
            'columns' => ['id', 'name', 'user_id', 'company_id'],
            'primaryKey' => ['id'],
            'constraints' => [
                'user_fk' => ['type' => 'foreign', 'columns' => ['user_id']],
                'company_fk' => ['type' => 'foreign', 'columns' => ['company_id']],
            ],
            'columnSchemas' => [
                'name' => ['type' => 'string', 'null' => false, 'default' => null, 'length' => 50],
                'user_id' => ['type' => 'integer', 'null' => true, 'default' => null],
                'company_id' => ['type' => 'integer', 'null' => false, 'default' => null],
            ],
        ]);

        $defaults = (new DefaultDataGuesser())
            ->setIncludeOptional(true)
            ->guessFor($table);

        $this->assertArrayHasKey('name', $defaults);
        $this->assertArrayNotHasKey('id', $defaults);
        $this->assertArrayNotHasKey('user_id', $defaults);
        $this->assertArrayNotHasKey('company_id', $defaults);
    }

    public function testSetIncludeOptionalReturnsSelfForChaining(): void
    {
        $guesser = new DefaultDataGuesser();
        $this->assertSame($guesser, $guesser->setIncludeOptional(true));
    }

    /**
     * Build a partial-mocked Table with the schema bits the guesser inspects.
     *
     * @param array{
     *     columns: array<string>,
     *     primaryKey: array<string>,
     *     columnSchemas: array<string, array<string, mixed>>,
     *     constraints?: array<string, array<string, mixed>>,
     * } $config
     */
    private function buildTable(array $config): Table
    {
        $schema = $this->getMockBuilder(TableSchema::class)
            ->onlyMethods(['constraints', 'getConstraint', 'indexes', 'columns', 'getColumn', 'getPrimaryKey'])
            ->setConstructorArgs(['test_table'])
            ->getMock();
        $schema->method('columns')->willReturn($config['columns']);
        $schema->method('getPrimaryKey')->willReturn($config['primaryKey']);
        $constraints = $config['constraints'] ?? [];
        $schema->method('constraints')->willReturn(array_keys($constraints));
        $schema->method('getConstraint')->willReturnCallback(
            static fn (string $name): ?array => $constraints[$name] ?? null,
        );
        $schema->method('indexes')->willReturn([]);
        $schema->method('getColumn')->willReturnCallback(
            static fn (string $col): ?array => $config['columnSchemas'][$col] ?? null,
        );

        $associations = $this->getMockBuilder(AssociationCollection::class)
            ->onlyMethods(['getIterator'])
            ->getMock();
        $associations->method('getIterator')->willReturn(new ArrayIterator([]));

        $table = $this->getMockBuilder(Table::class)
            ->onlyMethods(['getSchema', 'getAlias', 'associations'])
            ->getMock();
        $table->method('getSchema')->willReturn($schema);
        $table->method('getAlias')->willReturn('TestTable');
        $table->method('associations')->willReturn($associations);

        return $table;
    }
}
