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

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\FakeConfiguration;

/**
 * The API's change approval helpers: which mode a key's user is in for a zone,
 * who may review, and which zones a reviewer's list covers.
 */
#[CoversClass(ApiPermissionService::class)]
class ApiPermissionServiceChangeApprovalTest extends PermissionServiceTestCase
{

    private const ADMIN = 1;
    private const EDITOR = 2;      // zone_content_edit_own, owns zone 100
    private const REQUESTER = 3;   // zone_change_request_own only, owns zone 100
    private const REVIEWER = 4;    // edit_own + approve_own, owns zone 100
    private const CLIENT = 5;      // own_as_client under review for all, owns zone 100
    private const DELETER = 6;     // zone_delete_own only, owns zone 100
    private const NOBODY = 7;

    private const OWNED_ZONE = 100;
    private const OTHER_ZONE = 200;

    private function service(bool $enabled, bool $requireReviewForAll = false): ApiPermissionService
    {
        $db = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([self::OWNED_ZONE]);
        $db->method('prepare')->willReturn($stmt);

        $permissions = $this->buildPermissionService(
            permissionsByUser: [
                self::EDITOR => [Permission::PERM_ZONE_CONTENT_EDIT_OWN],
                self::REQUESTER => [Permission::PERM_ZONE_CHANGE_REQUEST_OWN],
                self::REVIEWER => [Permission::PERM_ZONE_CONTENT_EDIT_OWN, Permission::PERM_ZONE_CHANGE_APPROVE_OWN],
                self::CLIENT => [Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT],
                self::DELETER => [Permission::PERM_ZONE_DELETE_OWN],
            ],
            adminUserIds: [self::ADMIN],
            ownedZonesByUser: [
                self::EDITOR => [self::OWNED_ZONE],
                self::REQUESTER => [self::OWNED_ZONE],
                self::REVIEWER => [self::OWNED_ZONE],
                self::CLIENT => [self::OWNED_ZONE],
                self::DELETER => [self::OWNED_ZONE],
            ]
        );

        return new ApiPermissionService($db, $permissions, new FakeConfiguration([
            'approval' => ['enabled' => $enabled, 'require_review_for_all' => $requireReviewForAll],
        ]));
    }

    #[Test]
    public function testWithApprovalOffTheModeIsTodaysEditRule(): void
    {
        $service = $this->service(false);

        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, $service->getChangeApprovalMode(self::EDITOR, self::OWNED_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, $service->getChangeApprovalMode(self::EDITOR, self::OTHER_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, $service->getChangeApprovalMode(self::REQUESTER, self::OWNED_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, $service->getChangeApprovalMode(self::ADMIN, self::OTHER_ZONE));
    }

    #[Test]
    public function testWithApprovalOnRequestersFileAndEditorsWriteDirectly(): void
    {
        $service = $this->service(true);

        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, $service->getChangeApprovalMode(self::REQUESTER, self::OWNED_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, $service->getChangeApprovalMode(self::REQUESTER, self::OTHER_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, $service->getChangeApprovalMode(self::EDITOR, self::OWNED_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, $service->getChangeApprovalMode(self::NOBODY, self::OWNED_ZONE));
    }

    #[Test]
    public function testReviewForAllRoutesEditorsAndAdminsThroughRequests(): void
    {
        $service = $this->service(true, true);

        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, $service->getChangeApprovalMode(self::EDITOR, self::OWNED_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, $service->getChangeApprovalMode(self::ADMIN, self::OTHER_ZONE));
        $this->assertSame(ChangeApprovalPolicy::MODE_NONE, $service->getChangeApprovalMode(self::NOBODY, self::OWNED_ZONE));
    }

    #[Test]
    public function testReviewingNeedsTheApproveLevelAndTheEditPermission(): void
    {
        $service = $this->service(true);

        $this->assertTrue($service->canReviewChangeRequests(self::REVIEWER, self::OWNED_ZONE));
        $this->assertFalse($service->canReviewChangeRequests(self::REVIEWER, self::OTHER_ZONE));
        $this->assertFalse($service->canReviewChangeRequests(self::EDITOR, self::OWNED_ZONE));
        $this->assertFalse($service->canReviewChangeRequests(self::REQUESTER, self::OWNED_ZONE));
        $this->assertTrue($service->canReviewChangeRequests(self::ADMIN, self::OTHER_ZONE));
    }

    #[Test]
    public function testReviewableZonesAreAllForAdminsOwnedForOwnReviewersAndNoneOtherwise(): void
    {
        $service = $this->service(true);

        $this->assertNull($service->getReviewableZoneIds(self::ADMIN));
        $this->assertSame([self::OWNED_ZONE], $service->getReviewableZoneIds(self::REVIEWER));
        $this->assertSame([], $service->getReviewableZoneIds(self::EDITOR));
        $this->assertSame([], $service->getReviewableZoneIds(self::REQUESTER));
    }

    #[Test]
    public function testRestrictedRecordTypesNeedAnEditGrantOrARequestLevelThatCoversTheZone(): void
    {
        $service = $this->service(true, true);

        $this->assertTrue($service->canRequestZoneRecord(self::REQUESTER, self::OWNED_ZONE, 'NS', 'example.com', 'example.com'));
        $this->assertTrue($service->canRequestZoneRecord(self::CLIENT, self::OWNED_ZONE, 'A', 'www.example.com', 'example.com'));
        $this->assertFalse($service->canRequestZoneRecord(self::CLIENT, self::OWNED_ZONE, 'NS', 'example.com', 'example.com'));
        $this->assertFalse($service->canRequestZoneRecord(self::CLIENT, self::OWNED_ZONE, 'SOA', 'example.com', 'example.com'));
        $this->assertTrue($service->canRequestZoneRecord(self::EDITOR, self::OWNED_ZONE, 'SOA', 'example.com', 'example.com'));
    }

    #[Test]
    public function testZoneDeletionStaysDirectUnlessEveryChangeIsReviewed(): void
    {
        $this->assertFalse($this->service(true)->zoneDeleteRequiresApproval(self::DELETER, self::OWNED_ZONE));
        $this->assertTrue($this->service(true, true)->zoneDeleteRequiresApproval(self::DELETER, self::OWNED_ZONE));
        $this->assertFalse($this->service(false, true)->zoneDeleteRequiresApproval(self::DELETER, self::OWNED_ZONE));
    }

    #[Test]
    public function testRequestingAZoneDeletionFollowsTheRequestLevelOrTheDeleteGrantUnderReviewForAll(): void
    {
        $this->assertTrue($this->service(true)->canRequestZoneDelete(self::REQUESTER, self::OWNED_ZONE));
        $this->assertFalse($this->service(true)->canRequestZoneDelete(self::REQUESTER, self::OTHER_ZONE));
        $this->assertFalse($this->service(true)->canRequestZoneDelete(self::DELETER, self::OWNED_ZONE));
        $this->assertTrue($this->service(true, true)->canRequestZoneDelete(self::DELETER, self::OWNED_ZONE));
        $this->assertFalse($this->service(false)->canRequestZoneDelete(self::REQUESTER, self::OWNED_ZONE));
    }
}
