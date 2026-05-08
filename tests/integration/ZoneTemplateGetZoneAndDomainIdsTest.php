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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Model\ZoneTemplate;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Regression tests for ZoneTemplate::getZoneAndDomainIdsByTemplate().
 *
 * Guards two failure modes from the same code path:
 * - #1210: returning a single id confuses callers that need either the Poweradmin
 *   zones.id (for zone_template_sync FK) or the PowerDNS domains.id (for record
 *   updates). Each test asserts both ids come back independently.
 * - #945:  SQL-backend zones with a stale domain_id (no matching row in PowerDNS
 *   domains table) feed null into getDomainNameById and crash the template
 *   update with a TypeError. The INNER JOIN guard must drop those.
 *
 * Each test runs in its own process to keep the static permission cache inside
 * UserManager::verifyPermission from leaking across cases.
 */
class ZoneTemplateGetZoneAndDomainIdsTest extends SqliteIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PowerDNS domains table - only the columns the tested query references.
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL)");

        // Poweradmin zones / template tables.
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
    }

    private function makeTemplate(string $name = 'phpunit-template'): int
    {
        $stmt = $this->db->prepare("INSERT INTO zone_templ (name, owner) VALUES (:n, :o)");
        $stmt->execute([':n' => $name, ':o' => self::ADMIN_USER_ID]);
        return (int) $this->db->lastInsertId();
    }

    private function makeDomain(string $name): int
    {
        $stmt = $this->db->prepare("INSERT INTO domains (name) VALUES (:n)");
        $stmt->execute([':n' => $name]);
        return (int) $this->db->lastInsertId();
    }

    private function linkZone(int $domainId, int $templateId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:d, :o, :t)"
        );
        $stmt->execute([':d' => $domainId, ':o' => self::ADMIN_USER_ID, ':t' => $templateId]);
        return (int) $this->db->lastInsertId();
    }

    #[RunInSeparateProcess]
    public function testReturnsBothZoneIdAndDomainIdForLinkedZone(): void
    {
        $templateId = $this->makeTemplate();
        // Burn a few domain ids so the linked domain_id can't accidentally
        // equal zones.id - that coincidence was what masked #1210 in some
        // environments (FK passed by chance even though the wrong id was written).
        $this->makeDomain('filler-1.example.com');
        $this->makeDomain('filler-2.example.com');
        $domainId = $this->makeDomain('example.com');
        $zoneId = $this->linkZone($domainId, $templateId);

        $zoneTemplate = new ZoneTemplate($this->db, $this->config, $this->dnsBackendStub(false));

        $rows = $zoneTemplate->getZoneAndDomainIdsByTemplate($templateId, self::ADMIN_USER_ID);

        $this->assertCount(1, $rows);
        $this->assertSame($zoneId, $rows[0]['zone_id']);
        $this->assertSame($domainId, $rows[0]['domain_id']);
        $this->assertNotSame($rows[0]['zone_id'], $rows[0]['domain_id'], 'fixture must keep ids distinct - else the bug is masked');
    }

    #[RunInSeparateProcess]
    public function testSqlBackendInnerJoinSkipsOrphanedZones(): void
    {
        $templateId = $this->makeTemplate();
        $validDomainId = $this->makeDomain('valid.example.com');
        $validZoneId = $this->linkZone($validDomainId, $templateId);
        // Orphan: domains row never inserted, so INNER JOIN must drop this one.
        $orphanZoneId = $this->linkZone(99999, $templateId);

        $zoneTemplate = new ZoneTemplate($this->db, $this->config, $this->dnsBackendStub(false));

        $rows = $zoneTemplate->getZoneAndDomainIdsByTemplate($templateId, self::ADMIN_USER_ID);

        $this->assertCount(1, $rows, 'orphan zone with missing domains row should be filtered out');
        $this->assertSame($validZoneId, $rows[0]['zone_id']);
        $this->assertSame($validDomainId, $rows[0]['domain_id']);
        $this->assertNotEquals($orphanZoneId, $rows[0]['zone_id']);
    }

    #[RunInSeparateProcess]
    public function testApiBackendReturnsAllZonesWithoutJoinAgainstDomains(): void
    {
        // API mode never queries the PowerDNS domains table - the records live
        // behind the API. The method must return zones using only the local
        // zones table, including ones whose domain_id wouldn't match anything
        // in a (non-existent) local domains table.
        $templateId = $this->makeTemplate();
        $apiZoneId = $this->linkZone(99999, $templateId);

        $zoneTemplate = new ZoneTemplate($this->db, $this->config, $this->dnsBackendStub(true));

        $rows = $zoneTemplate->getZoneAndDomainIdsByTemplate($templateId, self::ADMIN_USER_ID);

        $this->assertCount(1, $rows);
        $this->assertSame($apiZoneId, $rows[0]['zone_id']);
        $this->assertSame(99999, $rows[0]['domain_id']);
    }

    #[RunInSeparateProcess]
    public function testIgnoresZonesAttachedToOtherTemplates(): void
    {
        $targetTemplateId = $this->makeTemplate('target');
        $otherTemplateId = $this->makeTemplate('other');

        $targetDomainId = $this->makeDomain('target.example.com');
        $otherDomainId = $this->makeDomain('other.example.com');

        $targetZoneId = $this->linkZone($targetDomainId, $targetTemplateId);
        $this->linkZone($otherDomainId, $otherTemplateId);

        $zoneTemplate = new ZoneTemplate($this->db, $this->config, $this->dnsBackendStub(false));

        $rows = $zoneTemplate->getZoneAndDomainIdsByTemplate($targetTemplateId, self::ADMIN_USER_ID);

        $this->assertCount(1, $rows);
        $this->assertSame($targetZoneId, $rows[0]['zone_id']);
    }
}
