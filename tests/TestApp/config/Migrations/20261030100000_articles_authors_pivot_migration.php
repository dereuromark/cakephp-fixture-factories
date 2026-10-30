<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Migrations\BaseMigration;

class ArticlesAuthorsPivotMigration extends BaseMigration
{
    public function up(): void
    {
        $this->table('articles_authors')
            ->addColumn('featured', 'boolean', [
                'default' => false,
                'null' => false,
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('articles_authors')
            ->removeColumn('featured')
            ->update();
    }
}
