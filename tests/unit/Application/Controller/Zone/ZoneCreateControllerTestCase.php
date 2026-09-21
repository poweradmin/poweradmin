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
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ZoneCreateService;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Domain\Service\Zone\ZoneSigningResult;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Shared fixture for the zone creation forms: the ownership resolver, the
 * zone service and the audit log are mocked, with the answers of each kept in
 * fields the test can script.
 */
abstract class ZoneCreateControllerTestCase extends SeamControllerTestCase
{
    /** @var list<string> Permissions the logged-in user holds */
    protected array $granted = [];

    protected ?string $ownerBlocker = null;
    protected bool $templateUsable = true;
    protected bool $dnssecAllowed = true;
    protected ZoneOwnershipResolution $ownership;

    /** @var list<array<string, mixed>> Answers handed out by createZone(), in order */
    protected array $createResults = [];

    /** @var list<array<int, mixed>> Arguments each createZone() call was made with */
    protected array $createCalls = [];

    /** @var list<array<int, mixed>> Arguments of each audit call, prefixed with the method name */
    protected array $audited = [];

    /** @var PermissionService&MockObject */
    protected PermissionService $permissions;

    /** @var ZoneManagementService&MockObject */
    protected ZoneManagementService $zones;

    /** @var DnssecProviderInterface&MockObject */
    protected DnssecProviderInterface $dnssec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownership = ZoneOwnershipResolution::success(self::USER_ID, []);

        $this->permissions = $this->createMock(PermissionService::class);
        $this->permissions->method('hasPermission')->willReturnCallback(
            fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true)
        );

        $formResolver = $this->createMock(ZoneOwnershipFormResolver::class);
        $formResolver->method('blocker')->willReturnCallback(fn(): ?string => $this->ownerBlocker);
        $formResolver->method('resolveInputs')->willReturnCallback(fn(): ZoneOwnershipResolution => $this->ownership);

        $ownershipResolver = $this->createMock(ZoneCreateOwnershipResolver::class);
        $ownershipResolver->method('canAssignOtherOwners')->willReturn(true);

        $this->zones = $this->createMock(ZoneManagementService::class);
        $this->zones->method('createZone')->willReturnCallback(function (...$args): array {
            $this->createCalls[] = $args;
            return array_shift($this->createResults) ?? self::created(count($this->createCalls));
        });

        $audit = $this->createMock(AuditService::class);
        foreach (['logZoneAdd', 'logSecondaryZoneImport'] as $method) {
            $audit->method($method)->willReturnCallback(function (...$args) use ($method): void {
                $this->audited[] = [$method, ...$args];
            });
        }

        $templateAccess = $this->createMock(ZoneTemplateAccessPolicy::class);
        $templateAccess->method('canCurrentUserUseTemplate')->willReturnCallback(fn(): bool => $this->templateUsable);

        $templates = $this->createMock(ZoneTemplateService::class);
        $templates->method('getDefaultTemplateId')->willReturn(null);
        $templates->method('getListZoneTempl')->willReturn([]);

        $apiPermissions = $this->createMock(ApiPermissionService::class);
        $apiPermissions->method('canManageDnssecForNewZone')->willReturnCallback(fn(): bool => $this->dnssecAllowed);

        $this->dnssec = $this->createMock(DnssecProviderInterface::class);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getUsersWithZoneCounts')->willReturn([['id' => self::USER_ID, 'username' => self::USERNAME]]);

        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('findAll')->willReturn([]);
        $groups->method('findByUserId')->willReturn([]);
        $groups->method('getMemberCountsByGroupIds')->willReturn([]);

        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('zoneOwnershipFormResolver')->willReturn($formResolver);
        $this->factory->method('zoneCreateOwnershipResolver')->willReturn($ownershipResolver);
        $this->factory->method('zoneManagementService')->willReturn($this->zones);
        $this->factory->method('auditService')->willReturn($audit);
        $this->factory->method('zoneTemplateService')->willReturn($templates);
        $this->factory->method('zoneTemplateAccessPolicy')->willReturn($templateAccess);
        $this->factory->method('apiPermissionService')->willReturn($apiPermissions);
        $this->factory->method('dnssecProvider')->willReturn($this->dnssec);
        $this->factory->method('userRepository')->willReturn($users);
        $this->factory->method('userGroupRepository')->willReturn($groups);

        // The real creation flow over the mocked collaborators, so the forms are
        // exercised as production wires them
        $this->factory->method('zoneCreateService')->willReturn(
            new ZoneCreateService($formResolver, $this->zones, $apiPermissions, $audit)
        );
    }

    /** @return array<string, mixed> */
    protected static function created(int $zoneId, ?ZoneSigningResult $dnssec = null): array
    {
        return ['success' => true, 'zone_id' => $zoneId, 'domain' => 'example.com', 'type' => 'MASTER', 'dnssec' => $dnssec];
    }

    /** @return array<string, mixed> */
    protected static function refusedWith(string $code, string $message = 'refused'): array
    {
        return ['success' => false, 'message' => $message, 'status' => 400, 'code' => $code];
    }

    protected function haltOf(callable $run): ControllerHalt
    {
        try {
            $run();
        } catch (ControllerHalt $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }
}
