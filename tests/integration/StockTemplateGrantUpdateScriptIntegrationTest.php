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

namespace Poweradmin\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The 4.1.0 update grants the stock powers to Zone Manager, DNS Editor and Read Only only when the
 * template has no permissions yet, so a custom template that reuses a stock name keeps its own.
 */
class StockTemplateGrantUpdateScriptIntegrationTest extends TestCase
{
    private const MARKER = '-- Assign permissions to Zone Manager template';
    private const UNTIL = '-- No Access template intentionally';

    private static function scratch(): string
    {
        return 'poweradmin_it_stockgrant_' . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    private function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            if ($engine === 'mysql') {
                $db = new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options);
                $db->exec('CREATE DATABASE IF NOT EXISTS ' . self::scratch());
                $db->exec('USE ' . self::scratch());
            } elseif ($engine === 'pgsql') {
                $db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
                $db->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
                $db->exec('CREATE SCHEMA ' . self::scratch());
                $db->exec('SET search_path TO ' . self::scratch());
            } else {
                $db = new PDO('sqlite::memory:', null, null, $options);
            }
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }

        return $db;
    }

    protected function tearDown(): void
    {
        try {
            (new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser'))->exec('DROP DATABASE IF EXISTS ' . self::scratch());
        } catch (PDOException) {
        }
        try {
            (new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin'))->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
        } catch (PDOException) {
        }
    }

    /** Templates 1 and 4 are the ones the script just created; 2 and 3 are custom templates with stock names */
    private function createTables(PDO $db, string $engine): void
    {
        $id = match ($engine) {
            'mysql' => 'id INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id SERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY',
        };
        $db->exec("CREATE TABLE perm_items ($id, name VARCHAR(64) NOT NULL, descr VARCHAR(1024) NOT NULL)");
        $db->exec("CREATE TABLE perm_templ ($id, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL)");
        $db->exec("CREATE TABLE perm_templ_items ($id, templ_id INT NOT NULL, perm_id INT NOT NULL)");

        $perms = ['zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
            'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
            'api_manage_keys', 'zone_delete_own', 'zone_content_edit_own_as_client'];
        $db->exec("INSERT INTO perm_items (name, descr) VALUES ('" . implode("', 'x'), ('", $perms) . "', 'x')");
        $db->exec("INSERT INTO perm_templ (name, descr) VALUES
            ('Zone Manager', 'x'), ('Zone Manager', 'custom'), ('Read Only', 'custom'), ('DNS Editor', 'x')");
        $db->exec("INSERT INTO perm_templ_items (templ_id, perm_id)
            SELECT 2, id FROM perm_items WHERE name = 'zone_content_view_own'");
        $db->exec("INSERT INTO perm_templ_items (templ_id, perm_id)
            SELECT 3, id FROM perm_items WHERE name = 'zone_content_view_own'");
    }

    /** @return list<string> */
    private function permsOf(PDO $db, int $templateId): array
    {
        $stmt = $db->prepare('SELECT pi.name FROM perm_templ_items pti
            JOIN perm_items pi ON pi.id = pti.perm_id WHERE pti.templ_id = ? ORDER BY pi.name');
        $stmt->execute([$templateId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function runGrants(PDO $db, string $engine): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 2) . "/sql/poweradmin-$engine-update-to-4.1.0.sql");
        $start = strpos($sql, self::MARKER);
        $this->assertNotFalse($start);
        $end = strpos($sql, self::UNTIL, $start);
        $this->assertNotFalse($end);
        $db->exec(substr($sql, $start, $end - $start));
    }

    #[DataProvider('engines')]
    public function testOnlyTheTemplateWithoutPermissionsIsGranted(string $engine): void
    {
        $db = $this->connect($engine);
        $this->createTables($db, $engine);

        $this->runGrants($db, $engine);
        $this->runGrants($db, $engine);

        $this->assertCount(11, $this->permsOf($db, 1));
        $this->assertSame(['zone_content_view_own'], $this->permsOf($db, 2));
        $this->assertSame(['zone_content_view_own'], $this->permsOf($db, 3));
        $this->assertSame(['search', 'user_edit_own', 'zone_content_edit_own_as_client', 'zone_content_view_own'], $this->permsOf($db, 4));
    }
}
