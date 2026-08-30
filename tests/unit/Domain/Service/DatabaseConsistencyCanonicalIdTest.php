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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DatabaseConsistencyService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The repair points a stranded zones row's domain_id at its own id. That is only valid in
 * API backend mode; in SQL mode domain_id is a domains foreign key, so the same write would
 * link the row to an unrelated zone and hand out ownership with it.
 */
class DatabaseConsistencyCanonicalIdTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER, zone_templ_id INTEGER DEFAULT 0, zone_name TEXT)");
    }

    private function service(bool $apiBackend): DatabaseConsistencyService
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('isApiBackend')->willReturn($apiBackend);

        $config = ConfigurationManager::getInstance();
        $config->initialize();

        return new DatabaseConsistencyService($this->db, $config, $provider);
    }

    private function seed(int $id, ?int $domainId, ?string $name): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (:id, :did, 1, :name)");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }

    private function domainIdOf(int $id): ?int
    {
        $stmt = $this->db->prepare("SELECT domain_id FROM zones WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (int)$value;
    }

    public function testSqlModeReportsNothingEvenWithStrandedRows(): void
    {
        $this->seed(1, 0, 'zero.example.com');
        $this->seed(2, null, 'null.example.com');

        $result = $this->service(false)->checkZonesHaveCanonicalIds();

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['data']);
    }

    public function testSqlModeRefusesToRepair(): void
    {
        $this->seed(1, 0, 'zero.example.com');

        $this->assertFalse($this->service(false)->fixZoneCanonicalId(1));
        $this->assertSame(0, $this->domainIdOf(1));
    }

    public function testFlagsZeroAndNullRowsOnly(): void
    {
        $this->seed(1, 0, 'zero.example.com');
        $this->seed(2, null, 'null.example.com');
        $this->seed(3, 3, 'healthy.example.com');
        $this->seed(4, 201, 'migrated.example.com');
        $this->seed(5, null, null);

        $result = $this->service(true)->checkZonesHaveCanonicalIds();

        $this->assertSame('warning', $result['status']);
        $this->assertSame([1, 2], array_column($result['data'], 'id'));
    }

    public function testReportsSuccessWhenEveryZoneResolves(): void
    {
        $this->seed(3, 3, 'healthy.example.com');

        $this->assertSame('success', $this->service(true)->checkZonesHaveCanonicalIds()['status']);
    }

    public function testRepairsZeroAndNullRows(): void
    {
        $this->seed(1, 0, 'zero.example.com');
        $this->seed(2, null, 'null.example.com');

        $service = $this->service(true);

        $this->assertTrue($service->fixZoneCanonicalId(1));
        $this->assertTrue($service->fixZoneCanonicalId(2));
        $this->assertSame(1, $this->domainIdOf(1));
        $this->assertSame(2, $this->domainIdOf(2));
    }

    public function testRepairIsIdempotentAndLeavesHealthyRowsAlone(): void
    {
        $this->seed(1, 0, 'zero.example.com');
        $this->seed(4, 201, 'migrated.example.com');

        $service = $this->service(true);
        $service->fixZoneCanonicalId(1);

        $this->assertFalse($service->fixZoneCanonicalId(1), 'a second repair must not report a change');
        $this->assertFalse($service->fixZoneCanonicalId(4), 'a healthy row must not be rewritten');
        $this->assertSame(201, $this->domainIdOf(4));
    }

    public function testRepairRefusesPlaceholderOwnershipRows(): void
    {
        // Placeholder rows are keyed by the canonical id of another zone; self-referencing
        // one would sever that link.
        $this->seed(5, null, null);

        $this->assertFalse($this->service(true)->fixZoneCanonicalId(5));
        $this->assertNull($this->domainIdOf(5));
    }

    public function testRepairRejectsNonPositiveIds(): void
    {
        $this->assertFalse($this->service(true)->fixZoneCanonicalId(0));
        $this->assertFalse($this->service(true)->fixZoneCanonicalId(-1));
    }

    /**
     * fixZoneWithoutOwner compares the canonical expression to :domain_id. Binding that id
     * as a string matched no row on SQLite, so the repair reported success and changed
     * nothing.
     */
    public function testFixZoneWithoutOwnerActuallyAssignsTheOwner(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (9, 9, NULL, 'orphan.example.com')");

        $this->assertTrue($this->service(true)->fixZoneWithoutOwner(9, 77));

        $stmt = $this->db->prepare("SELECT owner FROM zones WHERE id = 9");
        $stmt->execute();
        $this->assertSame(77, (int)$stmt->fetchColumn(), 'the repair reported success without writing');
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zones")->fetchColumn(), 'a duplicate row was inserted');
    }

    public function testFixZoneWithoutOwnerResolvesAStrandedRow(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (12, 0, NULL, 'stranded.example.com')");

        $this->assertTrue($this->service(true)->fixZoneWithoutOwner(12, 77));

        $stmt = $this->db->prepare("SELECT owner FROM zones WHERE id = 12");
        $stmt->execute();
        $this->assertSame(77, (int)$stmt->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM zones")->fetchColumn());
    }

    public function testFixAllCountsFixedAndFailedSeparately(): void
    {
        $this->seed(1, 0, 'zero.example.com');
        $this->seed(2, null, 'null.example.com');
        $this->seed(3, 3, 'healthy.example.com');

        $counts = $this->service(true)->fixAllZonesWithCanonicalIdIssue();

        $this->assertSame(['fixed' => 2, 'failed' => 0], $counts);
        $this->assertSame(3, $this->domainIdOf(3), 'the healthy row must be untouched');
        $this->assertSame('success', $this->service(true)->checkZonesHaveCanonicalIds()['status']);
    }
}
