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
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use ReflectionClass;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A template record name without a [ZONE] placeholder has to be qualified with
 * the zone when the template is applied, otherwise "www" is stored as the bare
 * label instead of "www.example.com".
 */
class DomainManagerTemplateNameQualificationTest extends SqliteIntegrationTestCase
{
    private const ZONE_ID = 555;
    private const TEMPLATE_ID = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE records_zone_templ (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, record_id TEXT, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zone_template_sync (id INTEGER PRIMARY KEY, zone_id INTEGER NOT NULL, zone_templ_id INTEGER, needs_sync INTEGER DEFAULT 0, last_synced TEXT, template_last_modified TEXT)");

        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, " . self::ZONE_ID . ", " . self::ADMIN_USER_ID . ", 0)");
        $this->db->exec("INSERT INTO zone_templ (id, name, descr, owner) VALUES (" . self::TEMPLATE_ID . ", 'tpl', '', " . self::ADMIN_USER_ID . ")");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::TEMPLATE_ID . ", 'www', 'A', '192.0.2.10', 300, 0)");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::TEMPLATE_ID . ", '[ZONE]', 'TXT', '\"v=spf1 -all\"', 300, 0)");
        $this->db->exec("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::TEMPLATE_ID . ", 'ftp.[ZONE]', 'CNAME', 'www.[ZONE]', 300, 0)");
    }

    #[RunInSeparateProcess]
    public function testTemplateRecordNamesAreQualifiedWithTheZone(): void
    {
        $this->makeDomainManager()->updateZoneRecords('sqlite', 3600, self::ZONE_ID, self::TEMPLATE_ID);

        $stmt = $this->db->query("SELECT name, type FROM records WHERE domain_id = " . self::ZONE_ID . " ORDER BY type");
        $written = $stmt->fetchAll(PDO::FETCH_NUM);

        $this->assertSame([
            ['www.example.com', 'A'],
            ['ftp.example.com', 'CNAME'],
            ['example.com', 'TXT'],
        ], $written);
    }

    private function makeDomainManager(): DomainManager
    {
        $config = $this->primeConfig();
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->method('getSOARecord')->willReturn('');
        $repo = $this->createMock(DomainRepositoryInterface::class);
        $repo->method('getDomainType')->willReturn('MASTER');
        $repo->method('getDomainNameById')->willReturn('example.com');
        $changeLogger = $this->createMock(RecordChangeLogger::class);

        return new DomainManager(
            $this->db,
            $config,
            $soa,
            $repo,
            $this->dnsBackendStub(false),
            null,
            $changeLogger
        );
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
