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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\Backend\DnsDataService;
use Poweradmin\Application\Service\Web\PaginationService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Model\ZoneSummary;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\User\UserPreferenceService;
use Poweradmin\Domain\Service\Auth\ZoneListPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipIndex;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Collaborators shared by the forward and reverse zone listings. Everything the
 * two pages branch on is a mutable property here, so a test states only the
 * condition it characterizes and both pages can be driven the same way.
 */
abstract class ZoneListControllerTestCase extends SeamControllerTestCase
{
    /** @var list<string> Permissions hasPermission() answers yes to */
    protected array $granted = [
        Permission::PERM_ZONE_CONTENT_VIEW_OWN,
        Permission::PERM_ZONE_CONTENT_VIEW_OTHERS,
    ];

    protected string $viewLevel = 'all';
    protected string $editLevel = 'all';
    protected string $deleteLevel = 'all';
    protected string $ownershipViewLevel = 'all';
    protected int $rowsPerPagePreference = 10;
    protected bool $showRecordCount = true;
    protected int $zoneCount = 3;

    /** @var list<array<string, mixed>> */
    protected array $zones = [];

    /** @var array{count_all: int, count_ipv4: int, count_ipv6: int} */
    protected array $reverseZoneCounts = ['count_all' => 3, 'count_ipv4' => 2, 'count_ipv6' => 1];

    protected ZoneOwnershipIndex $ownership;

    /** @var PermissionService&MockObject */
    protected PermissionService $permissions;

    /** @var UserPreferenceService&MockObject */
    protected UserPreferenceService $preferences;

    /** @var DnsDataService&MockObject */
    protected DnsDataService $dnsData;

    /** @var ZoneListPermissionService&MockObject */
    protected ZoneListPermissionService $zoneListPermissions;

    /** @var UserGroupRepositoryInterface&MockObject */
    protected UserGroupRepositoryInterface $userGroups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissions = $this->createMock(PermissionService::class);
        $this->permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));
        $this->permissions->method('getViewPermissionLevel')->willReturnCallback(fn(): string => $this->viewLevel);
        $this->permissions->method('getEditPermissionLevel')->willReturnCallback(fn(): string => $this->editLevel);
        $this->permissions->method('getDeletePermissionLevel')->willReturnCallback(fn(): string => $this->deleteLevel);
        $this->permissions->method('getZoneOwnershipViewPermissionLevel')
            ->willReturnCallback(fn(): string => $this->ownershipViewLevel);

        $this->preferences = $this->createMock(UserPreferenceService::class);
        $this->preferences->method('getShowZoneSerial')->willReturn(false);
        $this->preferences->method('getShowZoneTemplate')->willReturn(false);
        $this->preferences->method('getShowZoneRecordCount')->willReturnCallback(fn(): bool => $this->showRecordCount);
        $this->preferences->method('getRowsPerPage')->willReturnCallback(fn(): int => $this->rowsPerPagePreference);

        $this->dnsData = $this->createMock(DnsDataService::class);
        $this->dnsData->method('countZones')->willReturnCallback(fn(): int => $this->zoneCount);
        $this->dnsData->method('getForwardZones')->willReturnCallback(fn(): array => array_map(ZoneSummary::fromRow(...), $this->zones));
        $this->dnsData->method('getReverseZones')->willReturnCallback(fn(): array => array_map(ZoneSummary::fromRow(...), $this->zones));
        $this->dnsData->method('getReverseZoneCounts')->willReturnCallback(fn(): array => $this->reverseZoneCounts);
        $this->dnsData->method('getDistinctStartingLetters')->willReturn(['a', 'e']);

        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [], [], []);
        $this->zoneListPermissions = $this->createMock(ZoneListPermissionService::class);
        $this->zoneListPermissions->method('index')->willReturnCallback(fn(): ZoneOwnershipIndex => $this->ownership);

        $this->userGroups = $this->createMock(UserGroupRepositoryInterface::class);
        $this->userGroups->method('findAll')->willReturn([new UserGroup(7, 'netops', null, 1)]);

        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('userPreferenceService')->willReturn($this->preferences);
        $this->factory->method('paginationService')->willReturn(new PaginationService($this->preferences));
        $this->factory->method('dnsDataService')->willReturn($this->dnsData);
        $this->factory->method('zoneListPermissionService')->willReturn($this->zoneListPermissions);
        $this->factory->method('userGroupRepository')->willReturn($this->userGroups);
    }

    /**
     * Drops everything the previous run stored in the session, keeping the
     * logged-in user, so a second run in the same test starts clean.
     */
    protected function resetSession(): void
    {
        $this->session->clear();
        $this->session->set(SessionKeys::USERID, self::USER_ID);
        $this->session->set(SessionKeys::USERLOGIN, self::USERNAME);
    }
}
