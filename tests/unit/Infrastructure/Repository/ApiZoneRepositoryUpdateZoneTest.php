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
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;

/**
 * In API mode, updateZone() must only mirror a change into the local zones cache
 * when the PowerDNS API accepted it; a failed API call must not update local state
 * (audit M24).
 */
class ApiZoneRepositoryUpdateZoneTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, zone_master, owner, zone_templ_id)
            VALUES (1, 10, 'example.com', 'NATIVE', '', 1, 0)");
    }

    private function zoneType(): string
    {
        return (string) $this->db->query("SELECT zone_type FROM zones WHERE id = 1")->fetchColumn();
    }

    public function testLocalCacheUpdatedWhenApiSucceeds(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('updateZoneType')->willReturn(true);
        $repo = new ApiZoneRepository($this->db, $backend, 'mysql');

        $this->assertTrue($repo->updateZone(1, ['type' => 'MASTER']));
        $this->assertSame('MASTER', $this->zoneType());
    }

    public function testLocalCacheUntouchedWhenApiFails(): void
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('updateZoneType')->willReturn(false);
        $repo = new ApiZoneRepository($this->db, $backend, 'mysql');

        $this->assertFalse($repo->updateZone(1, ['type' => 'MASTER']));
        // The API rejected the change, so local state must stay as it was.
        $this->assertSame('NATIVE', $this->zoneType());
    }

    public function testFailedTypeUpdateShortCircuitsMasterUpdate(): void
    {
        // A failed type update must skip the master update entirely so a single
        // request cannot partially apply while reporting overall failure.
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('updateZoneType')->willReturn(false);
        $backend->expects($this->never())->method('updateZoneMaster');
        $repo = new ApiZoneRepository($this->db, $backend, 'mysql');

        $this->assertFalse($repo->updateZone(1, ['type' => 'MASTER', 'master' => '192.0.2.1']));
        $this->assertSame('', (string) $this->db->query("SELECT zone_master FROM zones WHERE id = 1")->fetchColumn());
    }
}
