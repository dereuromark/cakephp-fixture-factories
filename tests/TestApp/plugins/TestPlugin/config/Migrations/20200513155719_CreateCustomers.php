<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Migrations\AbstractMigration;

class CreateCustomers extends AbstractMigration
{
    /**
     * Change Method.
     *
     * @return void
     */
    public function up()
    {
        $generatedByDefault = class_exists(\Migrations\Db\Adapter\PostgresAdapter::class)
            ? \Migrations\Db\Adapter\PostgresAdapter::GENERATED_BY_DEFAULT
            : \Phinx\Db\Adapter\PostgresAdapter::GENERATED_BY_DEFAULT;

        $this->table('customers', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'generated' => $generatedByDefault,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('name', 'string', [
                'limit' => 128,
                'null' => false,
            ])
            ->addTimestamps('created', 'modified')
            ->create();
    }

    public function down(): void
    {
        $this->table('customers')->drop();
    }
}
