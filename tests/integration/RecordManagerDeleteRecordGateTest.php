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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use ReflectionClass;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * RecordManager::deleteRecord() checks the user's edit permission before the zone
 * type, so a refusal never tells whether a foreign zone is read-only.
 */
class RecordManagerDeleteRecordGateTest extends SqliteIntegrationTestCase
{
    private const EDITOR_USER_ID = 100;
    private const EDITOR_PERM_TEMPL_ID = 100;

    private const FOREIGN_ZONE_ID = 2;
    private const OWNED_ZONE_ID = 3;
    private const FOREIGN_RECORD_ID = 5;
    private const OWNED_RECORD_ID = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");

        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::FOREIGN_ZONE_ID . ", " . self::ADMIN_USER_ID . ")");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (2, " . self::OWNED_ZONE_ID . ", " . self::EDITOR_USER_ID . ")");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (" . self::FOREIGN_RECORD_ID . ", " . self::FOREIGN_ZONE_ID . ", 'www.foreign.example', 'A', '192.0.2.1', 3600, 0, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (" . self::OWNED_RECORD_ID . ", " . self::OWNED_ZONE_ID . ", 'www.owned.example', 'A', '192.0.2.2', 3600, 0, 0)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, 'zone_content_edit_own')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::EDITOR_PERM_TEMPL_ID . ", 'Editor')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::EDITOR_PERM_TEMPL_ID . ", 60)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::EDITOR_USER_ID . ", 'editor', " . self::EDITOR_PERM_TEMPL_ID . ")");
    }

    #[RunInSeparateProcess]
    public function testAForeignReadOnlyZoneGetsThePermissionRefusalNotItsType(): void
    {
        $this->assertSame(
            ['You do not have the permission to delete this record.'],
            $this->refusalsFor(self::FOREIGN_RECORD_ID)
        );
    }

    #[RunInSeparateProcess]
    public function testAnOwnedReadOnlyZoneIsStillRefusedAsReadOnly(): void
    {
        $this->assertSame(
            ['You cannot delete records from a read-only zone.'],
            $this->refusalsFor(self::OWNED_RECORD_ID)
        );
    }

    #[RunInSeparateProcess]
    public function testAnOwnedPrimaryZoneRecordIsStillDeleted(): void
    {
        $_SESSION['userid'] = self::EDITOR_USER_ID;

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())->method('deleteRecord')->with(self::OWNED_RECORD_ID)->willReturn(true);

        $this->assertTrue($this->manager($backend, 'MASTER')->deleteRecord(self::OWNED_RECORD_ID));
    }

    /**
     * @return string[]
     */
    private function refusalsFor(int $recordId): array
    {
        $_SESSION['userid'] = self::EDITOR_USER_ID;
        unset($_SESSION[SessionKeys::MESSAGES]);

        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('deleteRecord');

        $this->assertFalse($this->manager($backend, 'SLAVE')->deleteRecord($recordId));

        return array_column($_SESSION[SessionKeys::MESSAGES]['system'] ?? [], 'content');
    }

    private function manager(DnsBackendProvider $backend, string $zoneType): RecordManager
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn($zoneType);
        $domainRepository->method('getDomainNameById')->willReturn('example.com');

        return new RecordManager(
            $this->db,
            $this->primeConfig(),
            $this->createMock(DnsRecordValidationServiceInterface::class),
            $this->createMock(SOARecordManagerInterface::class),
            $domainRepository,
            $backend,
            null,
            $this->createMock(RecordChangeLogger::class)
        );
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $reflection->getProperty('settings')->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600],
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        return $config;
    }
}
