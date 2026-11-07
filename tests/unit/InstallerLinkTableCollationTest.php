<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DatabaseSchemaService;
use PoweradminInstall\DatabaseStructureHelper;

/**
 * Web-installed MySQL databases must carry the same binary collation as the shipped SQL file
 * on the SSO identity columns, or two subjects differing only in case collide on the unique key.
 */
class InstallerLinkTableCollationTest extends TestCase
{
    public function testInstallerCollatesTheSameColumnsAsTheShippedMysqlSchema(): void
    {
        require_once dirname(__DIR__, 2) . '/install/helpers/DatabaseStructureHelper.php';

        $installer = [];
        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            foreach ($table['fields'] as $name => $field) {
                if (($field['collation'] ?? '') === 'utf8mb4_bin') {
                    $installer[] = $table['table_name'] . '.' . $name;
                }
            }
        }

        $shipped = [];
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . '/sql/poweradmin-mysql-db-structure.sql');
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER);
        foreach ($tables as $table) {
            preg_match_all('/^\s*`(\w+)`[^\n]*COLLATE utf8mb4_bin/m', $table[2], $columns);
            foreach ($columns[1] as $column) {
                $shipped[] = $table[1] . '.' . $column;
            }
        }

        sort($installer);
        sort($shipped);
        $this->assertNotEmpty($shipped);
        $this->assertSame($shipped, $installer);
    }

    public function testACollatedColumnIsCreatedWithItsCollationOnMysql(): void
    {
        $created = [];
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use (&$created): int {
            $created[] = $sql;
            return 0;
        });

        (new DatabaseSchemaService($pdo))->createTable('t', [
            'subject' => ['type' => 'text', 'notnull' => 1, 'length' => 255, 'collation' => 'utf8mb4_bin'],
        ]);

        $this->assertStringContainsString('subject VARCHAR(255) COLLATE utf8mb4_bin NOT NULL', $created[0]);
    }
}
