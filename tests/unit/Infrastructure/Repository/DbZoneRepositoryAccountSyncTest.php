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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * Tests that zone ownership changes propagate to the PowerDNS account field (Issue #1358)
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryAccountSyncTest extends TestCase
{
    private PDO&MockObject $db;
    private ConfigurationManager&MockObject $config;
    private DnsBackendProvider&MockObject $backendProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(PDO::class);
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->backendProvider = $this->createMock(DnsBackendProvider::class);
    }

    private function setupConfig(bool $syncEnabled): void
    {
        $this->config->method('get')
            ->willReturnCallback(function ($group, $key, $default = null) use ($syncEnabled) {
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return null;
                }
                if ($group === 'dns' && $key === 'sync_zone_owner_to_account') {
                    return $syncEnabled;
                }
                return $default;
            });
    }

    private function setupStatements(string $ownerUsername): void
    {
        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use ($ownerUsername) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);
                $stmt->method('bindValue')->willReturn(true);
                $stmt->method('rowCount')->willReturn(1);
                if (str_contains($query, 'zone_templ_id FROM zones')) {
                    $stmt->method('fetch')->willReturn(['zone_templ_id' => 0]);
                }
                if (str_contains($query, 'u.username')) {
                    $stmt->method('fetchColumn')->willReturn($ownerUsername);
                }
                return $stmt;
            });
    }

    #[Test]
    public function addOwnerToZoneSyncsAccountWhenEnabled(): void
    {
        $this->setupConfig(true);
        $this->setupStatements('alice');
        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(42, 'alice')
            ->willReturn(true);

        $repository = new DbZoneRepository($this->db, $this->config, $this->backendProvider);
        $this->assertTrue($repository->addOwnerToZone(42, 5));
    }

    #[Test]
    public function removeOwnerFromZoneSyncsAccountWhenEnabled(): void
    {
        $this->setupConfig(true);
        $this->setupStatements('bob');
        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(42, 'bob')
            ->willReturn(true);

        $repository = new DbZoneRepository($this->db, $this->config, $this->backendProvider);
        $this->assertTrue($repository->removeOwnerFromZone(42, 5));
    }

    #[Test]
    public function addOwnerToZoneLeavesAccountAloneWhenDisabled(): void
    {
        $this->setupConfig(false);
        $this->setupStatements('alice');
        $this->backendProvider->expects($this->never())->method('updateZoneAccount');

        $repository = new DbZoneRepository($this->db, $this->config, $this->backendProvider);
        $this->assertTrue($repository->addOwnerToZone(42, 5));
    }

    #[Test]
    public function addOwnerToZoneToleratesMissingBackendProvider(): void
    {
        $this->setupConfig(true);
        $this->setupStatements('alice');

        $repository = new DbZoneRepository($this->db, $this->config);
        $this->assertTrue($repository->addOwnerToZone(42, 5));
    }
}
