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
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\ZoneAccountSyncService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for mirroring zone ownership into the PowerDNS account field (Issue #1358)
 */
#[CoversClass(ZoneAccountSyncService::class)]
class ZoneAccountSyncServiceTest extends TestCase
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

    private function setSyncEnabled(bool $enabled): void
    {
        $this->config->method('get')
            ->willReturnCallback(function ($group, $key, $default = null) use ($enabled) {
                if ($group === 'dns' && $key === 'sync_zone_owner_to_account') {
                    return $enabled;
                }
                return $default;
            });
    }

    private function expectOwnerQueryReturning(mixed $username): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($username);
        $this->db->method('prepare')->willReturn($stmt);
    }

    #[Test]
    public function syncIsDisabledByDefault(): void
    {
        $this->setSyncEnabled(false);
        $this->db->expects($this->never())->method('prepare');
        $this->backendProvider->expects($this->never())->method('updateZoneAccount');

        $service = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
        $service->syncZoneAccount(42);
    }

    #[Test]
    public function syncIsSkippedWithoutBackendProvider(): void
    {
        $this->setSyncEnabled(true);
        $this->db->expects($this->never())->method('prepare');

        $service = new ZoneAccountSyncService($this->db, $this->config, null);
        $service->syncZoneAccount(42);
    }

    #[Test]
    public function syncSendsOldestOwnerUsernameToBackend(): void
    {
        $this->setSyncEnabled(true);
        $this->expectOwnerQueryReturning('alice');
        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(42, 'alice')
            ->willReturn(true);

        $service = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
        $service->syncZoneAccount(42);
    }

    #[Test]
    public function syncClearsAccountWhenZoneHasNoDirectOwner(): void
    {
        $this->setSyncEnabled(true);
        $this->expectOwnerQueryReturning(false);
        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(42, '')
            ->willReturn(true);

        $service = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
        $service->syncZoneAccount(42);
    }

    #[Test]
    public function pushZoneAccountClearsAccountForNullUsername(): void
    {
        $this->setSyncEnabled(true);
        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(7, '')
            ->willReturn(true);

        $service = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
        $service->pushZoneAccount(7, null);
    }

    #[Test]
    public function pushZoneAccountIsSkippedWhenDisabled(): void
    {
        $this->setSyncEnabled(false);
        $this->backendProvider->expects($this->never())->method('updateZoneAccount');

        $service = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
        $service->pushZoneAccount(7, 'alice');
    }

    #[Test]
    public function isEnabledRequiresToggleAndProvider(): void
    {
        $this->setSyncEnabled(true);

        $withProvider = new ZoneAccountSyncService($this->db, $this->config, $this->backendProvider);
        $this->assertTrue($withProvider->isEnabled());

        $withoutProvider = new ZoneAccountSyncService($this->db, $this->config, null);
        $this->assertFalse($withoutProvider->isEnabled());
    }
}
