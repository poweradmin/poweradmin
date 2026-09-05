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

namespace integration;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Zone templates are private to their owner unless global. Applying a template by
 * id (zone creation, bulk registration, template change) must follow the same
 * scope as the template list, or a posted id copies another user's records.
 */
class ZoneTemplateOwnershipTest extends TestCase
{
    private const USER_ID = 100;
    private const ADMIN_ID = 200;
    private const OWN_TEMPLATE_ID = 10;
    private const FOREIGN_TEMPLATE_ID = 11;
    private const GLOBAL_TEMPLATE_ID = 12;

    private PDOLayer $db;

    protected function setUp(): void
    {
        $this->db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, perm_templ INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (53, 'user_is_ueberuser'), (56, 'zone_master_add')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'Creator'), (2, 'Admin')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 56), (2, 53)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'creator', 1), (" . self::ADMIN_ID . ", 'admin', 2)");

        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::OWN_TEMPLATE_ID . ", 'own', '', " . self::USER_ID . ")");
        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::FOREIGN_TEMPLATE_ID . ", 'foreign', '', 1)");
        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::GLOBAL_TEMPLATE_ID . ", 'global', '', 0)");
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testForeignTemplateIsRefused(): void
    {
        $_SESSION['userid'] = self::USER_ID;

        $this->assertFalse(ZoneTemplate::may_use_zone_templ($this->db, self::FOREIGN_TEMPLATE_ID, self::USER_ID));
        $this->assertFalse(ZoneTemplate::may_use_zone_templ($this->db, (string)self::FOREIGN_TEMPLATE_ID, self::USER_ID));
    }

    #[RunInSeparateProcess]
    public function testOwnAndGlobalTemplatesAreAllowed(): void
    {
        $_SESSION['userid'] = self::USER_ID;

        $this->assertTrue(ZoneTemplate::may_use_zone_templ($this->db, self::OWN_TEMPLATE_ID, self::USER_ID));
        $this->assertTrue(ZoneTemplate::may_use_zone_templ($this->db, self::GLOBAL_TEMPLATE_ID, self::USER_ID));
    }

    #[RunInSeparateProcess]
    public function testNoTemplateIsAllowed(): void
    {
        $_SESSION['userid'] = self::USER_ID;

        $this->assertTrue(ZoneTemplate::may_use_zone_templ($this->db, 'none', self::USER_ID));
        $this->assertTrue(ZoneTemplate::may_use_zone_templ($this->db, 0, self::USER_ID));
        $this->assertTrue(ZoneTemplate::may_use_zone_templ($this->db, '', self::USER_ID));
        $this->assertTrue(ZoneTemplate::may_use_zone_templ($this->db, null, self::USER_ID));
    }

    #[RunInSeparateProcess]
    public function testUnknownOrMalformedIdIsRefused(): void
    {
        $_SESSION['userid'] = self::USER_ID;

        $this->assertFalse(ZoneTemplate::may_use_zone_templ($this->db, 999, self::USER_ID));
        $this->assertFalse(ZoneTemplate::may_use_zone_templ($this->db, 'abc', self::USER_ID));
        $this->assertFalse(ZoneTemplate::may_use_zone_templ($this->db, ['11'], self::USER_ID));
    }

    #[RunInSeparateProcess]
    public function testAdministratorMayUseAnyTemplate(): void
    {
        $_SESSION['userid'] = self::ADMIN_ID;

        $this->assertTrue(ZoneTemplate::may_use_zone_templ($this->db, self::FOREIGN_TEMPLATE_ID, self::ADMIN_ID));
    }
}
