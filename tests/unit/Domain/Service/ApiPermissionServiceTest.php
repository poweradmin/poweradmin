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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use TestHelpers\BuildsPermissionService;

/**
 * The API permission gate as a decision table over facts: who is admin, who holds
 * which permission (own template or any group), who owns which zone.
 */
#[CoversClass(ApiPermissionService::class)]
class ApiPermissionServiceTest extends TestCase
{
    use BuildsPermissionService;

    private const ADMIN = 1;
    private const OTHERS = 2;   // holds every *_others grant
    private const OWN = 3;      // holds every *_own grant, owns zone 100
    private const CLIENT = 4;   // zone_content_edit_own_as_client, owns zone 100
    private const NOBODY = 5;
    private const SUBZONE = 6;  // client plus zone_content_edit_ns_subzone, owns zone 100

    private const OWNED_ZONE = 100;
    private const OTHER_ZONE = 200;

    private PDO&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(PDO::class);
    }

    private function service(array $extraGrants = [], array $superuserTemplates = [], array $templateByUser = []): ApiPermissionService
    {
        $grants = [
            self::OTHERS => [
                'zone_content_view_others', 'zone_content_edit_others', 'zone_delete_others',
                'zone_meta_edit_others', 'zone_metadata_view_others', 'zone_ownership_view_others',
                'user_view_others', 'user_edit_others', 'user_passwd_edit_others', 'user_add_new',
                'user_edit_templ_perm', 'zone_templ_add', 'zone_master_add', 'zone_slave_add',
            ],
            self::OWN => [
                'zone_content_view_own', 'zone_content_edit_own', 'zone_delete_own', 'zone_meta_edit_own',
                'zone_metadata_view_own', 'zone_ownership_view_own', 'user_edit_own', 'zone_dnssec_manage_own',
                'zone_templ_edit',
            ],
            self::CLIENT => ['zone_content_view_own', 'zone_content_edit_own_as_client'],
            self::SUBZONE => ['zone_content_edit_own_as_client', 'zone_content_edit_ns_subzone'],
            self::NOBODY => [],
        ];
        foreach ($extraGrants as $userId => $names) {
            $grants[$userId] = array_merge($grants[$userId] ?? [], $names);
        }

        return new ApiPermissionService($this->db, $this->buildPermissionService(
            permissionsByUser: $grants,
            adminUserIds: [self::ADMIN],
            ownedZonesByUser: [
                self::OWN => [self::OWNED_ZONE],
                self::CLIENT => [self::OWNED_ZONE],
                self::SUBZONE => [self::OWNED_ZONE],
            ],
            templateByUser: $templateByUser,
            superuserTemplateIds: $superuserTemplates
        ));
    }

    #[Test]
    public function testUserHasPermissionUnionsTemplatesAndTreatsAdminAsHoldingEverything(): void
    {
        $service = $this->service();

        $this->assertTrue($service->userHasPermission(self::OWN, 'zone_content_edit_own'));
        $this->assertFalse($service->userHasPermission(self::OWN, 'zone_content_edit_others'));
        $this->assertTrue($service->userHasPermission(self::ADMIN, 'anything_at_all'));
        $this->assertFalse($service->userHasPermission(self::NOBODY, 'zone_content_view_own'));
    }

    #[Test]
    public function testUserOwnsZoneComesFromTheRepository(): void
    {
        $service = $this->service();

        $this->assertTrue($service->userOwnsZone(self::OWN, self::OWNED_ZONE));
        $this->assertFalse($service->userOwnsZone(self::OWN, self::OTHER_ZONE));
        $this->assertFalse($service->userOwnsZone(self::NOBODY, self::OWNED_ZONE));
    }

    public static function zoneGates(): array
    {
        // method => [admin, others, own+owned, own+other zone, client+owned, nobody]
        return [
            'canViewZone' => ['canViewZone', true, true, true, false, true, false],
            'hasZoneContentEditPermission' => ['hasZoneContentEditPermission', true, true, true, false, false, false],
            'canEditZoneContent' => ['canEditZoneContent', true, true, true, false, true, false],
            'canDeleteZone' => ['canDeleteZone', true, true, true, false, false, false],
            'canManageDnssec' => ['canManageDnssec', true, false, true, false, false, false],
            'canEditZoneMeta' => ['canEditZoneMeta', true, true, true, false, false, false],
            'canViewZoneMetadata' => ['canViewZoneMetadata', true, true, true, false, false, false],
            'canViewZoneOwnership' => ['canViewZoneOwnership', true, true, true, false, false, false],
        ];
    }

    #[Test]
    #[DataProvider('zoneGates')]
    public function testZoneGatesCombineGrantAndOwnership(
        string $method,
        bool $admin,
        bool $others,
        bool $ownOwned,
        bool $ownOther,
        bool $clientOwned,
        bool $nobody
    ): void {
        $service = $this->service();

        $this->assertSame($admin, $service->$method(self::ADMIN, self::OTHER_ZONE), "$method admin");
        $this->assertSame($others, $service->$method(self::OTHERS, self::OTHER_ZONE), "$method others");
        $this->assertSame($ownOwned, $service->$method(self::OWN, self::OWNED_ZONE), "$method own+owned");
        $this->assertSame($ownOther, $service->$method(self::OWN, self::OTHER_ZONE), "$method own+other");
        $this->assertSame($clientOwned, $service->$method(self::CLIENT, self::OWNED_ZONE), "$method client+owned");
        $this->assertSame($nobody, $service->$method(self::NOBODY, self::OWNED_ZONE), "$method nobody");
    }

    #[Test]
    public function testMetadataViewersAlsoSeeViaTheirOwnViewGrants(): void
    {
        $service = $this->service([self::NOBODY => ['zone_metadata_view_others', 'zone_ownership_view_others']]);

        $this->assertTrue($service->canViewZoneMetadata(self::NOBODY, self::OTHER_ZONE));
        $this->assertTrue($service->canViewZoneOwnership(self::NOBODY, self::OTHER_ZONE));
        $this->assertFalse($service->canEditZoneMeta(self::NOBODY, self::OTHER_ZONE));
    }

    #[Test]
    public function testReadOnlyZoneTypesRefuseContentEditsEvenForAdmins(): void
    {
        $service = $this->service();

        foreach (['SLAVE', 'CONSUMER'] as $type) {
            $this->assertFalse($service->canEditZoneContent(self::ADMIN, self::OWNED_ZONE, $type), $type);
            $this->assertFalse($service->canEditZoneRecord(self::ADMIN, self::OWNED_ZONE, 'A', $type), $type);
        }
        $this->assertTrue($service->canEditZoneContent(self::OWN, self::OWNED_ZONE, 'MASTER'));
    }

    #[Test]
    public function testClientsMayNotWriteRestrictedTypesUnlessSubzoneNs(): void
    {
        $service = $this->service();

        $this->assertTrue($service->canEditZoneRecord(self::CLIENT, self::OWNED_ZONE, 'A'));
        foreach (['SOA', 'NS', 'LUA'] as $type) {
            $this->assertFalse($service->canEditZoneRecord(self::CLIENT, self::OWNED_ZONE, $type), $type);
        }
        // edit_own may write them
        $this->assertTrue($service->canEditZoneRecord(self::OWN, self::OWNED_ZONE, 'SOA'));

        // the subzone grant unlocks NS below the apex only, and only when names are known
        $this->assertTrue($service->canEditZoneRecord(self::SUBZONE, self::OWNED_ZONE, 'NS', 'MASTER', 'sub.example.com', 'example.com'));
        $this->assertFalse($service->canEditZoneRecord(self::SUBZONE, self::OWNED_ZONE, 'NS', 'MASTER', 'example.com', 'example.com'));
        $this->assertFalse($service->canEditZoneRecord(self::SUBZONE, self::OWNED_ZONE, 'NS'));
        $this->assertFalse($service->canEditZoneRecord(self::SUBZONE, self::OWNED_ZONE, 'SOA', 'MASTER', 'sub.example.com', 'example.com'));
        $this->assertFalse($service->canEditZoneRecord(self::CLIENT, self::OWNED_ZONE, 'NS', 'MASTER', 'sub.example.com', 'example.com'));
    }

    #[Test]
    public function testCanCreateZoneFollowsTheKindAndRefusesCatalogKindsForNonAdmins(): void
    {
        $service = $this->service([self::OWN => ['zone_slave_add']]);

        $this->assertTrue($service->canCreateZone(self::ADMIN, 'PRODUCER'));
        $this->assertTrue($service->canCreateZone(self::OTHERS, 'MASTER'));
        $this->assertTrue($service->canCreateZone(self::OTHERS, 'native'));
        $this->assertTrue($service->canCreateZone(self::OWN, 'SLAVE'));
        $this->assertFalse($service->canCreateZone(self::OWN, 'MASTER'));
        $this->assertFalse($service->canCreateZone(self::OTHERS, 'CONSUMER'));
        $this->assertFalse($service->canCreateZone(self::OTHERS, 'UNKNOWN'));
    }

    #[Test]
    public function testUserGates(): void
    {
        $service = $this->service();

        // view
        $this->assertTrue($service->canViewUser(self::NOBODY, self::NOBODY));
        $this->assertTrue($service->canViewUser(self::OTHERS, self::NOBODY));
        $this->assertFalse($service->canViewUser(self::NOBODY, self::OWN));
        $this->assertTrue($service->canListUsers(self::OTHERS));
        $this->assertFalse($service->canListUsers(self::NOBODY));

        // edit: self needs user_edit_own, others need user_edit_others, admins are off limits to delegates
        $this->assertTrue($service->canEditUser(self::OWN, self::OWN));
        $this->assertFalse($service->canEditUser(self::NOBODY, self::NOBODY));
        $this->assertTrue($service->canEditUser(self::OTHERS, self::OWN));
        $this->assertFalse($service->canEditUser(self::OTHERS, self::ADMIN));
        $this->assertTrue($service->canEditUser(self::ADMIN, self::OTHERS));

        // password: self always, others need the dedicated grant
        $this->assertTrue($service->canEditUserPassword(self::NOBODY, self::NOBODY));
        $this->assertTrue($service->canEditUserPassword(self::OTHERS, self::OWN));
        $this->assertFalse($service->canEditUserPassword(self::OWN, self::OTHERS));

        // create/delete
        $this->assertTrue($service->canCreateUser(self::OTHERS));
        $this->assertFalse($service->canCreateUser(self::OWN));
        $this->assertTrue($service->canDeleteUser(self::OTHERS, self::OWN));
        $this->assertFalse($service->canDeleteUser(self::OTHERS, self::OTHERS));
        $this->assertFalse($service->canDeleteUser(self::OTHERS, self::ADMIN));
        $this->assertTrue($service->canDeleteUser(self::ADMIN, self::OTHERS));

        // groups and templates
        $this->assertTrue($service->canManageGroups(self::ADMIN));
        $this->assertFalse($service->canManageGroups(self::OTHERS));
        $this->assertTrue($service->canEditPermissionTemplates(self::OTHERS));
        $this->assertFalse($service->canEditPermissionTemplates(self::OWN));
    }

    #[Test]
    public function testZoneTemplateGates(): void
    {
        $service = $this->service();

        $this->assertTrue($service->canViewZoneTemplates(self::OTHERS));
        $this->assertTrue($service->canViewZoneTemplates(self::OWN));
        $this->assertFalse($service->canViewZoneTemplates(self::CLIENT));
        $this->assertTrue($service->canCreateZoneTemplate(self::OTHERS));
        $this->assertFalse($service->canCreateZoneTemplate(self::OWN));
        $this->assertTrue($service->canEditZoneTemplate(self::OWN));

        $this->assertTrue($service->canWriteTemplateRecordType(self::OWN, 'SOA'));
        $this->assertFalse($service->canWriteTemplateRecordType(self::CLIENT, 'SOA'));
        $this->assertTrue($service->canWriteTemplateRecordType(self::CLIENT, 'A'));
    }

    #[Test]
    public function testTemplateAssignmentRules(): void
    {
        $service = $this->service(
            [self::OWN => ['user_edit_templ_perm']],
            superuserTemplates: [9],
            templateByUser: [self::NOBODY => 5]
        );

        $this->assertNull($service->checkPermissionTemplateAssignment(self::ADMIN, self::NOBODY, 9));
        $this->assertSame(ApiPermissionService::TEMPLATE_ASSIGN_DENIED, $service->checkPermissionTemplateAssignment(self::NOBODY, self::OWN, 5));
        $this->assertSame(ApiPermissionService::TEMPLATE_SELF_ASSIGN_DENIED, $service->checkPermissionTemplateAssignment(self::OWN, self::OWN, 5));
        $this->assertSame(ApiPermissionService::TEMPLATE_SUPERUSER_DENIED, $service->checkPermissionTemplateAssignment(self::OWN, self::NOBODY, 9));
        $this->assertNull($service->checkPermissionTemplateAssignment(self::OWN, self::NOBODY, 5));
        // echoing back the template the target already holds is not a change
        $this->assertNull($service->checkPermissionTemplateAssignment(self::NOBODY, self::NOBODY, 5));
        $this->assertTrue($service->templateGrantsSuperuser(9));
        $this->assertFalse($service->templateGrantsSuperuser(5));
    }

    #[Test]
    public function testCanManageDnssecForNewZone(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['3', '5']);
        $this->db->method('prepare')->willReturn($stmt);
        $service = $this->service();

        $this->assertTrue($service->canManageDnssecForNewZone(self::ADMIN, null));
        $this->assertFalse($service->canManageDnssecForNewZone(self::NOBODY, self::NOBODY));
        $this->assertTrue($service->canManageDnssecForNewZone(self::OWN, self::OWN));
        $this->assertFalse($service->canManageDnssecForNewZone(self::OWN, self::OTHERS));
        $this->assertFalse($service->canManageDnssecForNewZone(self::OWN, null));
        $this->assertTrue($service->canManageDnssecForNewZone(self::OWN, null, [5, 8]));
        $this->assertFalse($service->canManageDnssecForNewZone(self::OWN, null, [8, 9]));
    }

    #[Test]
    public function testGetUserGroupIdsReturnsIntegerList(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([':user_id' => 7])->willReturn(true);
        $stmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['3', '5', '7']);
        $this->db->expects($this->once())->method('prepare')->with($this->stringContains('user_group_members'))->willReturn($stmt);

        $this->assertSame([3, 5, 7], $this->service()->getUserGroupIds(7));
    }

    #[Test]
    public function testGetExistingGroupIdsBindsOnePlaceholderPerIdAndShortCircuitsOnEmptyInput(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([3, 5, 8])->willReturn(true);
        $stmt->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['3', '8']);
        $this->db->expects($this->once())->method('prepare')->with($this->matchesRegularExpression('/IN \(\?,\?,\?\)/'))->willReturn($stmt);
        $service = $this->service();

        $this->assertSame([], $service->getExistingGroupIds([]));
        $this->assertSame([3, 8], $service->getExistingGroupIds([3, 5, 8]));
    }

    #[Test]
    public function testGetUserVisibleZoneIds(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['1', '2']);
        $this->db->method('prepare')->willReturn($stmt);
        $service = $this->service();

        $this->assertNull($service->getUserVisibleZoneIds(self::ADMIN));
        $this->assertNull($service->getUserVisibleZoneIds(self::OTHERS));
        $this->assertSame([1, 2], $service->getUserVisibleZoneIds(self::OWN));
        $this->assertSame([], $service->getUserVisibleZoneIds(self::NOBODY));
    }
}
