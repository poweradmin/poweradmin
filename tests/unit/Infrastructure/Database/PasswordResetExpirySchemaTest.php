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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\DatabaseSchemaService;
use PoweradminInstall\DatabaseStructureHelper;

/**
 * password_reset_tokens.expires_at holds a UTC value (#1850). On MySQL it must be DATETIME:
 * a TIMESTAMP column reads the value in the session time zone and rejects one that falls in
 * the skipped spring-forward hour. PostgreSQL and SQLite keep timestamp.
 */
class PasswordResetExpirySchemaTest extends TestCase
{
    private static function sql(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 4) . '/sql/' . $file);
    }

    public function testMysqlStructureUsesDatetime(): void
    {
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE `password_reset_tokens`[^;]*`expires_at` datetime NOT NULL/is',
            self::sql('poweradmin-mysql-db-structure.sql')
        );
    }

    public function testMysqlUpdateConvertsTheColumn(): void
    {
        $this->assertMatchesRegularExpression(
            '/ALTER TABLE `password_reset_tokens` MODIFY `expires_at` datetime NOT NULL;/i',
            self::sql('poweradmin-mysql-update-to-4.6.0.sql')
        );
    }

    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql'], 'sqlite' => ['sqlite']];
    }

    /**
     * Links issued before the upgrade hold local time and would be read as UTC.
     */
    #[DataProvider('engines')]
    public function testUpdateRetiresOutstandingLinks(string $engine): void
    {
        $this->assertMatchesRegularExpression(
            '/UPDATE `?password_reset_tokens`? SET `?used`? = (1|TRUE) WHERE `?used`? = (0|FALSE);/i',
            self::sql("poweradmin-$engine-update-to-4.6.0.sql")
        );
    }

    public static function installerTypes(): array
    {
        return ['mysql' => ['mysql', 'datetime'], 'pgsql' => ['pgsql', 'timestamp'], 'sqlite' => ['sqlite', 'timestamp']];
    }

    #[DataProvider('installerTypes')]
    public function testInstallerColumnType(string $driver, string $expected): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';

        $queries = [];
        $db = $this->createMock(PDO::class);
        $db->method('getAttribute')->willReturn($driver);
        $db->method('quote')->willReturnCallback(fn ($v) => "'" . $v . "'");
        $db->method('exec')->willReturnCallback(function ($sql) use (&$queries) {
            $queries[] = $sql;
            return 0;
        });

        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            if ($table['table_name'] === 'password_reset_tokens') {
                (new DatabaseSchemaService($db))->createTable($table['table_name'], $table['fields'], $table['options']);
            }
        }

        $this->assertCount(1, $queries);
        $this->assertStringContainsString("expires_at $expected NOT NULL", $queries[0]);
    }

    public function testInstallerTableIsCreatableOnSqlite(): void
    {
        require_once dirname(__DIR__, 4) . '/install/helpers/DatabaseStructureHelper.php';
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach (DatabaseStructureHelper::getDefaultTables() as $table) {
            if ($table['table_name'] === 'password_reset_tokens') {
                (new DatabaseSchemaService($db))->createTable($table['table_name'], $table['fields'], $table['options']);
            }
        }

        $columns = array_column($db->query('PRAGMA table_info(password_reset_tokens)')->fetchAll(PDO::FETCH_ASSOC), null, 'name');
        $this->assertSame('timestamp', $columns['expires_at']['type']);
    }
}
