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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// The 4.1.0 stock-template grants must reach only the template the script creates,
// never an admin-made template that happens to share the name.
class StockTemplateGrantUpgradeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE perm_items (id integer PRIMARY KEY, name VARCHAR(64), descr VARCHAR(64))');
        $this->db->exec('CREATE TABLE perm_templ (id integer PRIMARY KEY AUTOINCREMENT, name VARCHAR(128), descr VARCHAR(1024))');
        $this->db->exec('CREATE TABLE perm_templ_items (id integer PRIMARY KEY AUTOINCREMENT, templ_id integer, perm_id integer)');

        $names = ['zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
            'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
            'api_manage_keys', 'zone_delete_own', 'zone_content_edit_own_as_client'];
        foreach ($names as $i => $name) {
            $this->db->exec("INSERT INTO perm_items (id, name, descr) VALUES (" . ($i + 1) . ", '$name', 'x')");
        }
    }

    private function runUpgradeGrants(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/../sql/poweradmin-sqlite-update-to-4.1.0.sql');
        $this->assertNotFalse($sql);
        $start = strpos($sql, '-- Add standard permission templates');
        $this->assertNotFalse($start);
        $this->db->exec(substr($sql, $start));
    }

    private function grantCount(string $template): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM perm_templ_items i JOIN perm_templ t ON t.id = i.templ_id WHERE t.name = :n'
        );
        $stmt->execute([':n' => $template]);
        return (int) $stmt->fetchColumn();
    }

    #[Test]
    public function customTemplatesWithStockNamesKeepTheirOwnGrants(): void
    {
        $this->db->exec("INSERT INTO perm_templ (id, name, descr) VALUES (10, 'Zone Manager', 'custom')");
        $this->db->exec("INSERT INTO perm_templ (id, name, descr) VALUES (11, 'DNS Editor', 'custom')");
        $this->db->exec("INSERT INTO perm_templ (id, name, descr) VALUES (12, 'Read Only', 'custom')");
        $this->db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (10, 3), (11, 3), (12, 3)');

        $this->runUpgradeGrants();

        $this->assertSame(1, $this->grantCount('Zone Manager'));
        $this->assertSame(1, $this->grantCount('DNS Editor'));
        $this->assertSame(1, $this->grantCount('Read Only'));
    }

    #[Test]
    public function freshStockTemplatesStillReceiveTheirGrants(): void
    {
        $this->runUpgradeGrants();

        $this->assertSame(11, $this->grantCount('Zone Manager'));
        $this->assertSame(4, $this->grantCount('DNS Editor'));
        $this->assertSame(2, $this->grantCount('Read Only'));
        $this->assertSame(0, $this->grantCount('No Access'));
    }

    #[Test]
    public function rerunningTheGrantsIsIdempotent(): void
    {
        $this->runUpgradeGrants();
        $this->runUpgradeGrants();

        $this->assertSame(11, $this->grantCount('Zone Manager'));
    }
}
