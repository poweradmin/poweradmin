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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Application\Controller\Zone\ListForwardZonesController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipIndex;
use Poweradmin\Application\Controller\RequestHalted;

/**
 * Characterizes the forward zone listing: the view gate, the sync action's own
 * gates, how paging, the letter filter and the sort allow-list are resolved,
 * and how ownership masks the owner/group cells of each row.
 */
#[CoversClass(ListForwardZonesController::class)]
class ListForwardZonesControllerTest extends ZoneListControllerTestCase
{
    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = []): ListForwardZonesController
    {
        // The router hands the controller the merged request, which is what
        // getSafeRequestValue() reads; the HttpRequest keeps them separate.
        return new ListForwardZonesController($this->requestData(), true, $this->environment($this->configure($config)));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function runController(array $config = []): ListForwardZonesController
    {
        $controller = $this->makeController($config);
        $controller->run();

        return $controller;
    }

    // ---------------------------------------------------------------- gates

    public function testEitherViewPermissionOpensThePage(): void
    {
        $this->granted = [Permission::PERM_ZONE_CONTENT_VIEW_OWN];
        $this->runController();
        $this->assertSame('list_forward_zones.html', $this->output->rendered[0][0]);

        $this->granted = [Permission::PERM_ZONE_CONTENT_VIEW_OTHERS];
        $this->runController();
        $this->assertSame('list_forward_zones.html', $this->output->rendered[0][0]);
    }

    public function testWithoutEitherViewPermissionThePageIsRefusedBeforeAnyQuery(): void
    {
        $this->granted = [];
        $this->dnsData->expects($this->never())->method('countZones');

        $controller = $this->makeController();

        $halt = $this->haltOf($controller);
        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertSame('You do not have sufficient permissions to view this page.', $halt->target);
        $this->assertSame([], $this->output->rendered);
    }

    public function testPermViewNoneStillFetchesTheZonesBeforeErroring(): void
    {
        // The "no zones" error is raised only after the page has queried and
        // decorated the zone rows; pinned as-is.
        $this->viewLevel = 'none';
        $this->dnsData->expects($this->atLeastOnce())->method('getForwardZones');

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('You do not have the permission to see any zones.', $halt->target);
    }

    // ------------------------------------------------------------ sync action

    public function testSyncOnAnSqlBackendRedirectsWithoutASyncOrMessage(): void
    {
        $this->post(['action' => 'sync']);

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame([], $this->messagesFor('list_forward_zones'));
    }

    public function testSyncOnAnApiBackendNeedsUeberuser(): void
    {
        $this->post(['action' => 'sync']);

        $halt = $this->haltOf($this->makeController(['dns' => ['backend' => 'api']]));

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/forward', $halt->target);
        $this->assertSame(
            [['error', 'You do not have permission to sync zones from PowerDNS.']],
            $this->messagesFor('list_forward_zones')
        );
    }

    public function testSyncActionOnAGetRequestJustListsTheZones(): void
    {
        $this->query(['action' => 'sync']);
        $controller = $this->runController(['dns' => ['backend' => 'api']]);

        $this->assertSame('list_forward_zones.html', $this->output->rendered[0][0]);
    }

    public function testAPostWithoutTheSyncActionJustListsTheZones(): void
    {
        $this->post(['action' => 'something-else']);
        $controller = $this->runController(['dns' => ['backend' => 'api']]);

        $this->assertCount(1, $this->output->rendered);
    }

    // --------------------------------------------------------- paging / rows

    /** @return array<string, array{0: string|null, 1: int}> */
    public static function rowStartProvider(): array
    {
        return [
            'no start parameter' => [null, 0],
            'first page' => ['1', 0],
            'third page' => ['3', 20],
            'zero clamps to the first row' => ['0', 0],
            'negative clamps to the first row' => ['-5', 0],
            'non-numeric is page zero, so the first row' => ['abc', 0],
        ];
    }

    #[DataProvider('rowStartProvider')]
    public function testStartParameterBecomesARowOffset(?string $start, int $expectedOffset): void
    {
        if ($start !== null) {
            $this->query(['start' => $start]);
        }
        $this->dnsData->expects($this->once())->method('getForwardZones')
            ->with('all', self::USER_ID, 'all', $expectedOffset, 10)
            ->willReturn([]);

        $this->runController();
    }

    public function testRequestedRowsPerPageWinsOverTheStoredPreference(): void
    {
        $this->query(['rows_per_page' => '25', 'start' => '2']);

        $this->runController();
        $this->assertSame(25, $this->renderedParams()['iface_rowamount']);
    }

    public function testRowsPerPageFallsBackToTheStoredPreferenceOverConfig(): void
    {
        $this->rowsPerPagePreference = 50;

        $this->runController(['interface' => ['rows_per_page' => 30]]);
        $this->assertSame(50, $this->renderedParams()['iface_rowamount']);
    }

    // ------------------------------------------------------------- letter filter

    public function testLetterFilterStaysOffWhileEverythingFitsOnOnePage(): void
    {
        $this->zoneCount = 10;
        $this->query(['letter' => 'b']);

        $this->runController();
        $params = $this->renderedParams();

        $this->assertSame('all', $params['letter_start']);
        // A short listing never even reads or stores the letter
        $this->assertFalse($this->session->has(SessionKeys::LETTER));
    }

    public function testALongListingDefaultsToTheLetterA(): void
    {
        $this->zoneCount = 11;

        $this->runController();
        $this->assertSame('a', $this->renderedParams()['letter_start']);
    }

    public function testASubmittedLetterIsUsedAndRemembered(): void
    {
        $this->zoneCount = 11;
        $this->query(['letter' => 'q']);

        $this->runController();
        $this->assertSame('q', $this->renderedParams()['letter_start']);
        $this->assertSame('q', $this->session->get(SessionKeys::LETTER));
    }

    public function testTheRememberedLetterIsUsedWhenNoneIsSubmitted(): void
    {
        $this->zoneCount = 11;
        $this->session->set(SessionKeys::LETTER, 'z');

        $this->runController();
        $this->assertSame('z', $this->renderedParams()['letter_start']);
    }

    public function testAnySubmittedLetterIsTakenVerbatim(): void
    {
        // No allow-list here: whatever arrives becomes the filter and is stored.
        $this->zoneCount = 11;
        $this->query(['letter' => '1 OR 1']);

        $this->runController();
        $this->assertSame('1 OR 1', $this->renderedParams()['letter_start']);
        $this->assertSame('1 OR 1', $this->session->get(SessionKeys::LETTER));
    }

    public function testTheAllLetterFetchesEveryZone(): void
    {
        $this->zoneCount = 11;
        $this->query(['letter' => 'all']);
        $this->dnsData->expects($this->once())->method('getForwardZones')
            ->with('all', self::USER_ID, 'all')->willReturn([]);

        $this->runController();
    }

    // --------------------------------------------------------------- sorting

    /** @return array<string, array{0: string|null, 1: string}> */
    public static function sortProvider(): array
    {
        return [
            'name is the default' => [null, 'name'],
            'type is allowed' => ['type', 'type'],
            'unknown column falls back to name' => ['serial', 'name'],
        ];
    }

    #[DataProvider('sortProvider')]
    public function testSortColumnIsCheckedAgainstTheAllowList(?string $submitted, string $expected): void
    {
        if ($submitted !== null) {
            $this->query(['zone_sort_by' => $submitted]);
        }

        $this->runController();
        $this->assertSame($expected, $this->renderedParams()['zone_sort_by']);
    }

    public function testOwnerSortNeedsTheOwnershipViewScope(): void
    {
        $this->query(['zone_sort_by' => 'owner']);

        $this->runController();
        $this->assertSame('owner', $this->renderedParams()['zone_sort_by']);
    }

    public function testOwnerSortIsDroppedWhenOwnershipIsOnlyVisibleForOwnZones(): void
    {
        // "own" ownership view with an "all" zone view would order rows by data
        // the user cannot see, so the column is dropped and sorting falls back
        $this->ownershipViewLevel = 'own';
        $this->query(['zone_sort_by' => 'owner']);

        $this->runController();
        $params = $this->renderedParams();

        $this->assertFalse($params['is_owner_sort_supported']);
        $this->assertSame('name', $params['zone_sort_by']);
    }

    public function testOwnerSortReturnsWhenTheListItselfIsLimitedToOwnedZones(): void
    {
        $this->ownershipViewLevel = 'own';
        $this->viewLevel = 'own';
        $this->query(['zone_sort_by' => 'owner']);

        $this->runController();
        $params = $this->renderedParams();

        $this->assertTrue($params['is_owner_sort_supported']);
        $this->assertSame('owner', $params['zone_sort_by']);
    }

    public function testGroupSortIsUnavailableOnAnApiBackend(): void
    {
        $this->query(['zone_sort_by' => 'group']);

        $this->runController();
        $this->assertSame('group', $this->renderedParams()['zone_sort_by']);

        $this->runController(['dns' => ['backend' => 'api']]);
        $params = $this->renderedParams();
        $this->assertFalse($params['is_group_sort_supported']);
        $this->assertSame('name', $params['zone_sort_by']);
    }

    public function testRecordCountSortNeedsTheColumnAndAnSqlBackend(): void
    {
        $this->query(['zone_sort_by' => 'count_records']);
        $this->runController();
        $this->assertSame('count_records', $this->renderedParams()['zone_sort_by']);

        $this->runController(['dns' => ['backend' => 'api']]);
        $this->assertSame('name', $this->renderedParams()['zone_sort_by']);

        $this->showRecordCount = false;
        $this->runController();
        $this->assertSame('name', $this->renderedParams()['zone_sort_by']);
    }

    public function testSortDirectionIsNormalisedAndUnknownValuesFallBackToAscending(): void
    {
        $this->query(['zone_sort_by_direction' => 'desc']);
        $this->runController();
        $this->assertSame('DESC', $this->renderedParams()['zone_sort_direction']);

        $this->resetSession();
        $this->query(['zone_sort_by_direction' => 'sideways']);
        $this->runController();
        $this->assertSame('ASC', $this->renderedParams()['zone_sort_direction']);
    }

    public function testASubmittedSortIsRememberedForTheNextRequest(): void
    {
        $this->query(['zone_sort_by' => 'type', 'zone_sort_by_direction' => 'DESC']);
        $this->runController();

        $this->query([]);
        $this->runController();
        $params = $this->renderedParams();

        $this->assertSame('type', $params['zone_sort_by']);
        $this->assertSame('DESC', $params['zone_sort_direction']);
    }

    // ------------------------------------------------------------- columns

    public function testOwnershipViewNoneHidesTheOwnerGroupAndFullNameColumns(): void
    {
        $this->ownershipViewLevel = 'none';

        $this->runController(['interface' => ['display_fullname_in_zone_list' => true]]);
        $params = $this->renderedParams();

        $this->assertFalse($params['show_owner_column']);
        $this->assertFalse($params['show_group_column']);
        $this->assertFalse($params['iface_zonelist_fullname']);
        // The 4.4.0 theme aliases mirror the gated flags
        $this->assertSame($params['show_owner_column'], $params['is_user_owner_allowed']);
        $this->assertSame($params['show_group_column'], $params['is_group_owner_allowed']);
    }

    public function testZoneOwnershipModeSuppressesTheMatchingColumn(): void
    {
        $this->runController(['dns' => ['zone_ownership_mode' => 'users_only']]);
        $usersOnly = $this->renderedParams();
        $this->assertTrue($usersOnly['show_owner_column']);
        $this->assertFalse($usersOnly['show_group_column']);

        $this->runController(['dns' => ['zone_ownership_mode' => 'groups_only']]);
        $groupsOnly = $this->renderedParams();
        $this->assertFalse($groupsOnly['show_owner_column']);
        $this->assertTrue($groupsOnly['show_group_column']);
    }

    // ------------------------------------------------------------ zone rows

    public function testGroupNamesAreResolvedAndUnknownIdsGetAPlaceholder(): void
    {
        $this->zones = [['id' => '1', 'name' => 'example.com', 'owners' => [], 'full_names' => []]];
        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [7], [], [1 => [7, 99]]);

        $this->runController();
        $zone = $this->renderedParams()['zones'][0];

        $this->assertSame(['netops', 'Group #99'], $zone['groups']);
    }

    public function testEachRowCarriesItsOwnDeleteEligibility(): void
    {
        $this->deleteLevel = 'own';
        $this->zones = [
            ['id' => '1', 'name' => 'mine.example.com', 'owners' => [], 'full_names' => []],
            ['id' => '2', 'name' => 'theirs.example.com', 'owners' => [], 'full_names' => []],
        ];
        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [], [1 => [self::USER_ID]], []);

        $this->runController();
        $zones = $this->renderedParams()['zones'];

        $this->assertTrue($zones[0]['user_can_delete']);
        $this->assertFalse($zones[1]['user_can_delete']);
    }

    public function testAtOwnOwnershipScopeForeignRowsLoseTheirOwnerCells(): void
    {
        $this->ownershipViewLevel = 'own';
        $this->zones = [
            ['id' => '1', 'name' => 'mine.example.com', 'owners' => ['tester'], 'full_names' => ['Tess Ter']],
            ['id' => '2', 'name' => 'theirs.example.com', 'owners' => ['other'], 'full_names' => ['Other Person']],
        ];
        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [], [1 => [self::USER_ID]], [1 => [7], 2 => [7]]);

        $this->runController();
        $zones = $this->renderedParams()['zones'];

        $this->assertSame(['tester'], $zones[0]['owners']);
        $this->assertSame(['netops'], $zones[0]['groups']);
        $this->assertSame([], $zones[1]['owners']);
        $this->assertSame([], $zones[1]['full_names']);
        $this->assertSame([], $zones[1]['groups']);
        $this->assertSame([], $zones[1]['groups_display']['visible']);
    }

    public function testWithApprovalDisabledNoPendingRequestsAreLookedUp(): void
    {
        $this->zones = [['id' => '1', 'name' => 'example.com', 'owners' => [], 'full_names' => []]];
        $this->factory->expects($this->never())->method('zoneChangeRequestRepository');

        $this->runController();
        $this->assertSame([], $this->renderedParams()['pending_change_requests_by_zone']);
    }

    public function testPaginationLinksCarryThePageSizeExactlyOnce(): void
    {
        // The forward list passes no extra query parameters of its own; the
        // page size is appended by the presenter. The reverse list passes
        // rows_per_page explicitly as well, which is where the two diverge.
        $this->zoneCount = 100;
        $this->query(['rows_per_page' => '20']);

        $this->runController();
        $pagination = $this->renderedParams()['pagination'];

        $this->assertStringContainsString('/zones/forward?start=1&rows_per_page=20', $pagination);
        // five page links plus "Next"
        $this->assertSame(6, substr_count($pagination, 'rows_per_page=20'));
    }

    private function haltOf(ListForwardZonesController $controller): RequestHalted
    {
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }
}
