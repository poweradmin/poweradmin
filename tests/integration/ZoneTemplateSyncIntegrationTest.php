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
use PDOException;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneTemplateSyncService;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;

/**
 * Integration test covering zone_template_sync reconciliation against a real database.
 *
 * Issue #1249: zone_template_sync keeps stale entries when changing a zone template.
 * @see https://github.com/poweradmin/poweradmin/issues/1249
 */
class ZoneTemplateSyncIntegrationTest extends TestCase
{
    private ?PDO $db = null;
    private array $createdZoneIds = [];
    private array $createdTemplateIds = [];

    private const DB_HOST = '127.0.0.1';
    private const DB_PORT = '3306';
    private const DB_NAME = 'poweradmin';
    private const DB_USER = 'pdns';
    private const DB_PASS = 'poweradmin';

    protected function setUp(): void
    {
        try {
            $this->db = new PDO(
                'mysql:host=' . self::DB_HOST . ';port=' . self::DB_PORT . ';dbname=' . self::DB_NAME,
                self::DB_USER,
                self::DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('Poweradmin database not available: ' . $e->getMessage());
        }

        try {
            $this->db->query('SELECT 1 FROM zone_template_sync LIMIT 1');
        } catch (PDOException $e) {
            $this->markTestSkipped('zone_template_sync table missing: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdZoneIds as $zoneId) {
            try {
                $this->db->exec("DELETE FROM zone_template_sync WHERE zone_id = $zoneId");
                $this->db->exec("DELETE FROM zones WHERE id = $zoneId");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
        foreach ($this->createdTemplateIds as $templateId) {
            try {
                $this->db->exec("DELETE FROM zone_templ WHERE id = $templateId");
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
        $this->db = null;
    }

    private function makeService(): ZoneTemplateSyncService
    {
        $config = new FakeConfiguration([
            'database' => ['type' => 'mysql'],
        ]);
        return new ZoneTemplateSyncService($this->db, $config);
    }

    private function createTemplate(string $name): int
    {
        $stmt = $this->db->prepare("INSERT INTO zone_templ (name, descr, owner, created_by) VALUES (:name, :descr, 0, NULL)");
        $stmt->execute([':name' => $name, ':descr' => 'integration test']);
        $id = (int)$this->db->lastInsertId();
        $this->createdTemplateIds[] = $id;
        return $id;
    }

    private function createZone(int $domainId, int $templateId): int
    {
        $stmt = $this->db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:domain_id, 0, :templ_id)");
        $stmt->execute([':domain_id' => $domainId, ':templ_id' => $templateId]);
        $id = (int)$this->db->lastInsertId();
        $this->createdZoneIds[] = $id;
        return $id;
    }

    private function countSyncRows(int $zoneId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM zone_template_sync WHERE zone_id = :id");
        $stmt->execute([':id' => $zoneId]);
        return (int)$stmt->fetchColumn();
    }

    private function fetchTemplateIds(int $zoneId): array
    {
        $stmt = $this->db->prepare("SELECT zone_templ_id FROM zone_template_sync WHERE zone_id = :id ORDER BY zone_templ_id");
        $stmt->execute([':id' => $zoneId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRemoveStaleSyncRecordsKeepsOnlyTargetTemplate(): void
    {
        $templateA = $this->createTemplate('Integ-A-' . uniqid());
        $templateB = $this->createTemplate('Integ-B-' . uniqid());
        $zoneId = $this->createZone(900000 + random_int(1, 99999), $templateB);

        $service = $this->makeService();
        $service->createSyncRecord($zoneId, $templateA);
        $service->markTemplateAsModified($templateA);
        $service->createSyncRecord($zoneId, $templateB);
        $service->markZoneAsSynced($zoneId, $templateB);

        $this->assertSame(2, $this->countSyncRows($zoneId), 'expected stale + new sync rows before reconciliation');

        $service->removeStaleSyncRecords($zoneId, $templateB);

        $this->assertSame([$templateB], $this->fetchTemplateIds($zoneId));
    }

    public function testRemoveStaleSyncRecordsWithZeroClearsAllRows(): void
    {
        $templateA = $this->createTemplate('Integ-A-' . uniqid());
        $zoneId = $this->createZone(900000 + random_int(1, 99999), $templateA);

        $service = $this->makeService();
        $service->createSyncRecord($zoneId, $templateA);

        $this->assertSame(1, $this->countSyncRows($zoneId));

        $service->removeStaleSyncRecords($zoneId, 0);

        $this->assertSame(0, $this->countSyncRows($zoneId));
    }

    public function testGetUnsyncedZoneCountReflectsReconciliation(): void
    {
        $templateA = $this->createTemplate('Integ-A-' . uniqid());
        $templateB = $this->createTemplate('Integ-B-' . uniqid());
        $zoneId = $this->createZone(900000 + random_int(1, 99999), $templateB);

        $service = $this->makeService();
        $service->createSyncRecord($zoneId, $templateA);
        $service->markTemplateAsModified($templateA);

        $this->assertSame(1, $service->getUnsyncedZoneCount($templateA), 'stale row should report as unsynced before fix');

        $service->removeStaleSyncRecords($zoneId, $templateB);

        $this->assertSame(0, $service->getUnsyncedZoneCount($templateA), 'no unsynced zones expected after reconciliation');
    }
}
