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

namespace Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * A template apply that is refused for lack of rights has to return false and
 * leave the zone, its template records and their mapping untouched.
 */
class DomainManagerTemplateApplyRefusalTest extends TestCase
{
    private const USER_ID = 2;
    private const ZONE_ID = 555;
    private const TEMPLATE_ID = 9;

    private PDOCommon $db;

    protected function setUp(): void
    {
        $this->db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '')");
        $this->db->exec("CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER NOT NULL, perm_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL, perm_templ INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT NOT NULL, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (1, 'zone_content_edit_own'), (2, 'zone_master_add')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (1, 'EditOwnOnly'), (2, 'AddOnly')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'user', 1)");
        $_SESSION['userid'] = self::USER_ID;

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id TEXT, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0, last_synced TEXT, template_last_modified TEXT)");

        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::TEMPLATE_ID . ", 'tpl', '', " . self::USER_ID . ")");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::TEMPLATE_ID . ", 'www', 'A', '192.0.2.10', 300, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (77, " . self::ZONE_ID . ", 'old.example.com', 'A', '192.0.2.1', 300, 0)");
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (" . self::ZONE_ID . ", 77, " . self::TEMPLATE_ID . ")");
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testOwnerWithoutZoneAddRightsIsRefusedBeforeTheTemplateRecordsAreDeleted(): void
    {
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 1)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, " . self::ZONE_ID . ", " . self::USER_ID . ", " . self::TEMPLATE_ID . ")");

        $result = $this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertFalse($result);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM records WHERE id = 77")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM records_zone_templ WHERE record_id = 77")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM records")->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testUserWithoutEditRightsOnTheZoneIsRefusedBeforeAnyWrite(): void
    {
        $this->db->exec("UPDATE users SET perm_templ = 2 WHERE id = " . self::USER_ID);
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (2, 2)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, " . self::ZONE_ID . ", 99, " . self::TEMPLATE_ID . ")");

        $result = $this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID);

        $this->assertFalse($result);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM records WHERE id = 77")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM records_zone_templ WHERE record_id = 77")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM records")->fetchColumn());
    }

    private function makeDomainManager(): DomainManager
    {
        $config = $this->primeConfig();
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->method('getSOARecord')->willReturn('');
        $repo = $this->createMock(DomainRepositoryInterface::class);
        $repo->method('getDomainType')->willReturn('MASTER');
        $repo->method('getDomainNameById')->willReturn('example.com');

        return new DomainManager($this->db, $config, $soa, $repo);
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $settingsProperty->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => [
                'ttl' => 3600,
                'ns1' => 'ns1.example.com', 'ns2' => 'ns2.example.com', 'ns3' => '', 'ns4' => '',
                'hostmaster' => 'hostmaster.example.com',
                'soa_refresh' => 28800, 'soa_retry' => 7200, 'soa_expire' => 604800, 'soa_minimum' => 86400,
            ],
        ]);
        $initializedProperty->setValue($config, true);

        return $config;
    }
}
