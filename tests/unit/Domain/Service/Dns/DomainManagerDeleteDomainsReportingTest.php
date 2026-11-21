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
use TestHelpers\SqliteIntegrationTestCase;

/** A bulk delete reports each zone it removed, so a failure in the batch does not hide the others from the audit log. */
class DomainManagerDeleteDomainsReportingTest extends SqliteIntegrationTestCase
{
    private const CALLER_ID = 7;
    private const PERM_TEMPL_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [
                'CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)',
                'CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)',
                'CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)',
                'CREATE TABLE log_zones (id INTEGER PRIMARY KEY, event TEXT NOT NULL, created_at TEXT, priority INTEGER, zone_id INTEGER)',
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (40, 'zone_content_edit_others')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::PERM_TEMPL_ID . ", 'Caller')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::PERM_TEMPL_ID . ", 40)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CALLER_ID . ", 'caller', " . self::PERM_TEMPL_ID . ")");
        $_SESSION['userid'] = self::CALLER_ID;
    }

    #[RunInSeparateProcess]
    public function testReportsTheZonesRemovedWhenALaterOneFails(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->method('deleteZone')->willReturnCallback(fn(int $id, string $name): bool => $id !== 6);
        $repository = $this->createMock(DomainRepositoryInterface::class);
        $repository->method('getDomainNameById')->willReturn('example.com');

        $manager = new DomainManager(
            $this->db,
            $this->createMock(ConfigurationManager::class),
            $this->createMock(SOARecordManagerInterface::class),
            $repository,
            $backend
        );

        $this->assertSame(['deleted' => [5, 7], 'failed' => [6]], $manager->deleteDomainsReporting([5, 6, 7]));
        $this->assertFalse($manager->deleteDomains([6]));
    }
}
