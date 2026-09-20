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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\SearchController;
use Poweradmin\Application\Service\DnsDataService;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Domain\Service\ZoneListPermissionService;
use Poweradmin\Domain\Service\ZoneOwnershipIndex;

/**
 * Characterizes what the search page decides for itself: the permission gate,
 * whether a search runs at all, how the two page sizes and the two sort
 * allow-lists are resolved, and the per-row edit/delete flags. Marshalling the
 * query parameters is SearchCriteria's job and is tested with that class.
 */
#[CoversClass(SearchController::class)]
class SearchControllerTest extends SeamControllerTestCase
{
    /** @var list<string> */
    private array $granted = [Permission::PERM_SEARCH];

    private string $viewLevel = 'all';
    private string $editLevel = 'all';
    private string $deleteLevel = 'all';
    private string $ownershipViewLevel = 'all';

    /** @var list<array<string, mixed>> */
    private array $foundZones = [];

    /** @var list<array<string, mixed>> */
    private array $foundRecords = [];

    private ZoneOwnershipIndex $ownership;

    /** @var DnsDataService&MockObject */
    private DnsDataService $dnsData;

    /** @var PermissionService&MockObject */
    private PermissionService $permissions;

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

        $preferences = $this->createMock(UserPreferenceService::class);
        $preferences->method('getRowsPerPage')->willReturn(10);

        $this->dnsData = $this->createMock(DnsDataService::class);
        $this->dnsData->method('searchZones')->willReturnCallback(fn(): array => $this->foundZones);
        $this->dnsData->method('searchRecords')->willReturnCallback(fn(): array => $this->foundRecords);
        $this->dnsData->method('searchZonesTotalCount')->willReturn(42);
        $this->dnsData->method('searchRecordsTotalCount')->willReturn(7);

        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [], [], []);
        $zoneListPermissions = $this->createMock(ZoneListPermissionService::class);
        $zoneListPermissions->method('index')->willReturnCallback(fn(): ZoneOwnershipIndex => $this->ownership);

        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('userPreferenceService')->willReturn($preferences);
        $this->factory->method('paginationService')->willReturn(new PaginationService($preferences));
        $this->factory->method('dnsDataService')->willReturn($this->dnsData);
        $this->factory->method('zoneListPermissionService')->willReturn($zoneListPermissions);
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = []): TestableSearchController
    {
        return new TestableSearchController(array_merge($_GET, $_POST), $this->environment($this->configure($config)));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function runController(array $config = []): TestableSearchController
    {
        $controller = $this->makeController($config);
        $controller->run();

        return $controller;
    }

    // ---------------------------------------------------------------- gate

    public function testSearchingNeedsTheSearchPermission(): void
    {
        $this->granted = [];
        $this->post(['query' => 'example.com']);
        $this->dnsData->expects($this->never())->method('searchZones');

        try {
            $this->makeController()->run();
            $this->fail('Expected the permission check to end the request.');
        } catch (ControllerHalt $halt) {
            $this->assertSame(ControllerHalt::KIND_PERMISSION, $halt->kind);
            $this->assertSame('You do not have the permission to perform searches.', $halt->target);
        }
    }

    // ------------------------------------------------- which searches run

    public function testAGetRequestRendersTheEmptyFormWithoutSearching(): void
    {
        $this->dnsData->expects($this->never())->method('searchZones');
        $this->dnsData->expects($this->never())->method('searchRecords');

        $params = $this->runController()->renderedParams();

        $this->assertSame([], $params['found_zones']);
        $this->assertSame([], $params['found_records']);
        $this->assertSame(0, $params['total_zones']);
        $this->assertSame(0, $params['total_records']);
        $this->assertSame(1, $params['zones_page']);
        $this->assertSame(1, $params['records_page']);
    }

    public function testAPostRunsBothSearchesEvenWhenOnlyOneBoxIsTicked(): void
    {
        // The zones/records checkboxes travel inside the criteria to the
        // repository; the controller itself always issues both searches.
        $this->post(['query' => 'example.com', 'zones' => '1']);
        $this->dnsData->expects($this->once())->method('searchZones')->willReturn([]);
        $this->dnsData->expects($this->once())->method('searchRecords')->willReturn([]);

        $params = $this->runController()->renderedParams();

        $this->assertSame(42, $params['total_zones']);
        $this->assertSame(7, $params['total_records']);
    }

    public function testTheSearchesRunAtTheUsersViewScope(): void
    {
        $this->viewLevel = 'own';
        $this->post(['query' => 'example.com']);
        $this->dnsData->expects($this->once())->method('searchZones')
            ->with($this->anything(), 'own')->willReturn([]);
        $this->dnsData->expects($this->once())->method('searchRecords')
            ->with($this->anything(), 'own')->willReturn([]);

        $this->runController();
    }

    /** @return array<string, array{0: string|null, 1: int}> */
    public static function pageProvider(): array
    {
        return [
            'absent' => [null, 1],
            'first' => ['1', 1],
            'third' => ['3', 3],
            'zero clamps up' => ['0', 1],
            'negative clamps up' => ['-4', 1],
            'non-numeric clamps up' => ['later', 1],
        ];
    }

    #[DataProvider('pageProvider')]
    public function testResultPagesNeverGoBelowOne(?string $submitted, int $expected): void
    {
        $fields = ['query' => 'example.com'];
        if ($submitted !== null) {
            $fields['zones_page'] = $submitted;
            $fields['records_page'] = $submitted;
        }
        $this->post($fields);

        $params = $this->runController()->renderedParams();

        $this->assertSame($expected, $params['zones_page']);
        $this->assertSame($expected, $params['records_page']);
    }

    // ----------------------------------------------------------- page sizes

    public function testTheLegacyRowsPerPageControlSetsBothLists(): void
    {
        $this->post(['query' => 'a', 'rows_per_page' => '30', 'zones_rows_per_page' => '15']);

        $params = $this->runController()->renderedParams();

        $this->assertSame(30, $params['zone_rowamount']);
        $this->assertSame(30, $params['record_rowamount']);
    }

    public function testTheTwoListsCanTakeSeparatePageSizes(): void
    {
        $this->post(['query' => 'a', 'zones_rows_per_page' => '15', 'records_rows_per_page' => '25']);

        $params = $this->runController()->renderedParams();

        $this->assertSame(15, $params['zone_rowamount']);
        $this->assertSame(25, $params['record_rowamount']);
    }

    public function testOutOfRangePageSizesFallBackToTheResolvedDefault(): void
    {
        $this->post(['query' => 'a', 'zones_rows_per_page' => '9999', 'records_rows_per_page' => 'lots']);

        $params = $this->runController()->renderedParams();

        $this->assertSame(10, $params['zone_rowamount']);
        $this->assertSame(10, $params['record_rowamount']);
    }

    public function testOnAGetRequestOnlyTheResolvedDefaultApplies(): void
    {
        // The posted controls are ignored entirely outside a POST
        $this->query(['rows_per_page' => '50']);

        $params = $this->runController()->renderedParams();

        $this->assertSame(50, $params['zone_rowamount']);
        $this->assertSame(50, $params['record_rowamount']);
    }

    // --------------------------------------------------------------- sorting

    /** @return array<string, array{0: string, 1: string}> */
    public static function zoneSortProvider(): array
    {
        return [
            'name' => ['name', 'name'],
            'type' => ['type', 'type'],
            'record count' => ['count_records', 'count_records'],
            'owner full name' => ['fullname', 'fullname'],
            'unknown falls back to name' => ['owner', 'name'],
        ];
    }

    #[DataProvider('zoneSortProvider')]
    public function testZoneSortAllowList(string $submitted, string $expected): void
    {
        $this->query(['zone_sort_by' => $submitted]);

        $this->assertSame($expected, $this->runController()->renderedParams()['zone_sort_by']);
    }

    public function testFullnameSortNeedsTheOwnershipViewScope(): void
    {
        $this->ownershipViewLevel = 'own';
        $this->query(['zone_sort_by' => 'fullname']);

        $params = $this->runController()->renderedParams();

        $this->assertFalse($params['is_owner_sort_supported']);
        $this->assertSame('name', $params['zone_sort_by']);
    }

    public function testFullnameSortReturnsWhenResultsAreLimitedToOwnedZones(): void
    {
        $this->ownershipViewLevel = 'own';
        $this->viewLevel = 'own';
        $this->query(['zone_sort_by' => 'fullname']);

        $params = $this->runController()->renderedParams();

        $this->assertTrue($params['is_owner_sort_supported']);
        $this->assertSame('fullname', $params['zone_sort_by']);
    }

    public function testRecordCountSortIsUnavailableOnAnApiBackend(): void
    {
        $this->query(['zone_sort_by' => 'count_records']);

        $params = $this->runController(['dns' => ['backend' => 'api']])->renderedParams();

        $this->assertFalse($params['is_record_count_sort_supported']);
        $this->assertSame('name', $params['zone_sort_by']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function recordSortProvider(): array
    {
        return [
            'name' => ['name', 'name'],
            'type' => ['type', 'type'],
            'prio' => ['prio', 'prio'],
            'content' => ['content', 'content'],
            'ttl' => ['ttl', 'ttl'],
            'disabled' => ['disabled', 'disabled'],
            'record count is not a record column' => ['count_records', 'name'],
        ];
    }

    #[DataProvider('recordSortProvider')]
    public function testRecordSortAllowList(string $submitted, string $expected): void
    {
        $this->query(['record_sort_by' => $submitted]);

        $this->assertSame($expected, $this->runController()->renderedParams()['record_sort_by']);
    }

    public function testTheTwoSortBucketsAreRememberedSeparately(): void
    {
        $this->query(['zone_sort_by' => 'type', 'record_sort_by' => 'ttl']);
        $this->runController();

        $this->query([]);
        $params = $this->runController()->renderedParams();

        $this->assertSame('type', $params['zone_sort_by']);
        $this->assertSame('ttl', $params['record_sort_by']);
    }

    // -------------------------------------------------------- per-row flags

    public function testPerRowFlagsFollowOwnershipNotJustTheGlobalLevel(): void
    {
        $this->editLevel = 'own';
        $this->deleteLevel = 'own';
        $this->foundZones = [
            ['id' => 1, 'name' => 'mine.example.com'],
            ['id' => 2, 'name' => 'theirs.example.com'],
        ];
        $this->foundRecords = [
            ['domain_id' => 1, 'name' => 'a.mine.example.com', 'type' => 'A', 'content' => '192.0.2.1'],
            ['domain_id' => 2, 'name' => 'a.theirs.example.com', 'type' => 'A', 'content' => '192.0.2.2'],
        ];
        // Owned through a group, not directly
        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [3], [], [1 => [3]]);
        $this->post(['query' => 'example.com']);

        $params = $this->runController()->renderedParams();

        $this->assertTrue($params['found_zones'][0]['user_can_edit']);
        $this->assertTrue($params['found_zones'][0]['user_can_delete']);
        $this->assertFalse($params['found_zones'][1]['user_can_edit']);
        $this->assertTrue($params['found_records'][0]['user_can_edit']);
        $this->assertFalse($params['found_records'][1]['user_can_edit']);
    }

    public function testAtOwnOwnershipScopeForeignZonesLoseTheirOwnerColumns(): void
    {
        $this->ownershipViewLevel = 'own';
        $this->foundZones = [
            ['id' => 1, 'name' => 'mine.example.com', 'fullname' => 'Tess Ter', 'owner_usernames' => 'tester'],
            ['id' => 2, 'name' => 'theirs.example.com', 'fullname' => 'Other Person', 'owner_usernames' => 'other'],
        ];
        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [], [1 => [self::USER_ID]], []);
        $this->post(['query' => 'example.com']);

        $zones = $this->runController()->renderedParams()['found_zones'];

        $this->assertSame('Tess Ter', $zones[0]['fullname']);
        $this->assertSame('', $zones[1]['fullname']);
        $this->assertArrayNotHasKey('owner_usernames', $zones[1]);
        $this->assertTrue($this->runController()->renderedParams()['show_zone_owners']);
    }

    public function testOwnershipViewNoneHidesTheOwnerColumnEntirely(): void
    {
        $this->ownershipViewLevel = 'none';

        $this->assertFalse($this->runController()->renderedParams()['show_zone_owners']);
    }

    public function testBulkActionsFollowTheDeleteAndEditScopes(): void
    {
        $this->deleteLevel = 'none';
        $this->editLevel = 'own';

        $params = $this->runController()->renderedParams();

        $this->assertFalse($params['can_bulk_delete_zones']);
        $this->assertTrue($params['can_bulk_delete_records']);
    }

    // ---------------------------------------------------------- ipv6 display

    public function testIpv6ContentAndReverseNamesAreShortenedForDisplay(): void
    {
        $this->foundRecords = [
            [
                'domain_id' => 1,
                'name' => '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
                'type' => 'PTR',
                'content' => 'host.example.com',
            ],
            [
                'domain_id' => 1,
                'name' => 'host.example.com',
                'type' => 'AAAA',
                'content' => '2001:0db8:0000:0000:0000:0000:0000:0001',
            ],
        ];
        $this->post(['query' => 'example.com']);

        $records = $this->runController()->renderedParams()['found_records'];

        $this->assertSame('2001:db8::1', $records[1]['content']);
        $this->assertStringStartsWith('2001:db8::1', $records[0]['display_name']);
        // The stored name is left intact next to the display copy
        $this->assertStringEndsWith('ip6.arpa', $records[0]['name']);
    }

    public function testRecordsFallBackToTheirNameAsDisplayName(): void
    {
        // A GET renders no records, so this is the POST path's default
        $this->foundRecords = [['domain_id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1']];
        $this->post(['query' => 'example.com']);

        $records = $this->runController()->renderedParams()['found_records'];

        $this->assertSame('www.example.com', $records[0]['display_name']);
    }
}
