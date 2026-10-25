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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use ReflectionClass;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Applying a template writes records straight to the backend, so it takes the
 * same content-edit standing as editing those records by hand; meta-edit alone
 * is not enough.
 */
class DomainManagerUpdateZoneRecordsGateTest extends SqliteIntegrationTestCase
{
    private const CALLER_ID = 7;
    private const CALLER_PERM_TEMPL_ID = 7;
    private const ZONE_ID = 555;
    private const TEMPLATE_ID = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id TEXT, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0, last_synced TEXT, template_last_modified TEXT)");

        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::TEMPLATE_ID . ", 'tpl', '', 0)");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::TEMPLATE_ID . ", 'www', 'A', '192.0.2.10', 300, 0)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (40, 'zone_content_edit_own'), (41, 'zone_meta_edit_others'), (42, 'zone_master_add')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::CALLER_PERM_TEMPL_ID . ", 'Caller')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CALLER_ID . ", 'caller', " . self::CALLER_PERM_TEMPL_ID . ")");

        $_SESSION['userid'] = self::CALLER_ID;
    }

    #[RunInSeparateProcess]
    public function testMetaEditAndCreateGrantsAloneAreRefused(): void
    {
        $this->grant('zone_meta_edit_others', 'zone_master_add');
        $this->ownZone(false);

        $this->assertFalse($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(0, $this->recordCount());
    }

    #[RunInSeparateProcess]
    public function testEditOwnWithoutOwnershipIsRefused(): void
    {
        $this->grant('zone_content_edit_own', 'zone_master_add');
        $this->ownZone(false);

        $this->assertFalse($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(0, $this->recordCount());
    }

    #[RunInSeparateProcess]
    public function testEditOwnOnAnOwnedZoneAppliesTheTemplate(): void
    {
        $this->grant('zone_content_edit_own', 'zone_master_add');
        $this->ownZone(true);

        $this->assertTrue($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID));
        $this->assertSame(1, $this->recordCount());
    }

    #[RunInSeparateProcess]
    public function testUnlinkingTheTemplateWritesNoRecordsSoMetaEditIsEnough(): void
    {
        $this->grant('zone_meta_edit_others');
        $this->ownZone(false);

        $this->assertTrue($this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, 0));
    }

    private function grant(string ...$permissions): void
    {
        $stmt = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) SELECT :templ, id FROM perm_items WHERE name = :name");
        foreach ($permissions as $permission) {
            $stmt->execute([':templ' => self::CALLER_PERM_TEMPL_ID, ':name' => $permission]);
        }
    }

    private function ownZone(bool $owns): void
    {
        $owner = $owns ? self::CALLER_ID : self::ADMIN_USER_ID;
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, " . self::ZONE_ID . ", $owner, 0)");
    }

    private function recordCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM records WHERE domain_id = " . self::ZONE_ID)->fetchColumn();
    }

    private function makeDomainManager(): DomainManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $reflection->getProperty('settings')->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => [
                'ttl' => 3600,
                'ns1' => 'ns1.example.com', 'ns2' => 'ns2.example.com', 'ns3' => '', 'ns4' => '',
                'hostmaster' => 'hostmaster.example.com',
                'soa_refresh' => 28800, 'soa_retry' => 7200, 'soa_expire' => 604800, 'soa_minimum' => 86400,
            ],
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->method('getSOARecord')->willReturn('');
        $repo = $this->createMock(DomainRepositoryInterface::class);
        $repo->method('getDomainType')->willReturn('MASTER');
        $repo->method('getDomainNameById')->willReturn('example.com');

        return new DomainManager(
            $this->db,
            $config,
            $soa,
            $repo,
            $this->dnsBackendStub(false),
            null,
            $this->createMock(RecordChangeLogger::class)
        );
    }
}
