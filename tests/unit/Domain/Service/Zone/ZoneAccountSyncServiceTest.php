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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneAccountOwnerLookupInterface;
use Poweradmin\Domain\Service\Zone\ZoneAccountSyncService;
use TestHelpers\FakeConfiguration;

/**
 * Tests for mirroring zone ownership into the PowerDNS account field (Issue #1358)
 */
#[CoversClass(ZoneAccountSyncService::class)]
class ZoneAccountSyncServiceTest extends TestCase
{
    private ZoneAccountOwnerLookupInterface&MockObject $owners;
    private FakeConfiguration $config;
    private DnsBackendProviderInterface&MockObject $backendProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owners = $this->createMock(ZoneAccountOwnerLookupInterface::class);
        $this->config = new FakeConfiguration();
        $this->backendProvider = $this->createMock(DnsBackendProviderInterface::class);
    }

    private function setSyncEnabled(bool $enabled): void
    {
        $this->config = new FakeConfiguration(['dns' => ['sync_zone_owner_to_account' => $enabled]]);
    }

    private function expectOwnerQueryReturning(?string $username): void
    {
        $this->owners->method('oldestOwnerUsername')->with(42)->willReturn($username);
    }

    #[Test]
    public function syncIsDisabledByDefault(): void
    {
        $this->setSyncEnabled(false);
        $this->owners->expects($this->never())->method('oldestOwnerUsername');
        $this->backendProvider->expects($this->never())->method('updateZoneAccount');

        $service = new ZoneAccountSyncService($this->owners, $this->config, $this->backendProvider);
        $service->syncZoneAccount(42);
    }

    #[Test]
    public function syncIsSkippedWithoutBackendProvider(): void
    {
        $this->setSyncEnabled(true);
        $this->owners->expects($this->never())->method('oldestOwnerUsername');

        $service = new ZoneAccountSyncService($this->owners, $this->config, null);
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

        $service = new ZoneAccountSyncService($this->owners, $this->config, $this->backendProvider);
        $service->syncZoneAccount(42);
    }

    #[Test]
    public function syncClearsAccountWhenZoneHasNoDirectOwner(): void
    {
        $this->setSyncEnabled(true);
        $this->expectOwnerQueryReturning(null);
        $this->backendProvider->expects($this->once())
            ->method('updateZoneAccount')
            ->with(42, '')
            ->willReturn(true);

        $service = new ZoneAccountSyncService($this->owners, $this->config, $this->backendProvider);
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

        $service = new ZoneAccountSyncService($this->owners, $this->config, $this->backendProvider);
        $service->pushZoneAccount(7, null);
    }

    #[Test]
    public function pushZoneAccountIsSkippedWhenDisabled(): void
    {
        $this->setSyncEnabled(false);
        $this->backendProvider->expects($this->never())->method('updateZoneAccount');

        $service = new ZoneAccountSyncService($this->owners, $this->config, $this->backendProvider);
        $service->pushZoneAccount(7, 'alice');
    }

    #[Test]
    public function isEnabledRequiresToggleAndProvider(): void
    {
        $this->setSyncEnabled(true);

        $withProvider = new ZoneAccountSyncService($this->owners, $this->config, $this->backendProvider);
        $this->assertTrue($withProvider->isEnabled());

        $withoutProvider = new ZoneAccountSyncService($this->owners, $this->config, null);
        $this->assertFalse($withoutProvider->isEnabled());
    }
}
