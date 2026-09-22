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
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Zone\ListReverseZonesController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipIndex;
use Poweradmin\Application\Controller\RequestHalted;

/**
 * Characterizes the reverse zone listing next to its forward twin: the same
 * view gate, paging, sort allow-list and ownership masking, plus what only this
 * page has - the ipv4/ipv6 filter, natural name sorting, the IPv6 display name
 * and the associated forward zones.
 */
#[CoversClass(ListReverseZonesController::class)]
class ListReverseZonesControllerTest extends ZoneListControllerTestCase
{
    /** @var ZoneRepositoryInterface&MockObject */
    private ZoneRepositoryInterface $zoneRepository;

    /** @var list<array<string, string>> Rows findForwardZonesByPtrRecords() answers with */
    private array $ptrMatches = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->zoneRepository->method('findForwardZonesByPtrRecords')
            ->willReturnCallback(fn(): array => $this->ptrMatches);
        $this->factory->method('zoneRepository')->willReturn($this->zoneRepository);
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(array $config = []): ListReverseZonesController
    {
        return new ListReverseZonesController($this->requestData(), true, $this->environment($this->configure($config)));
    }

    /** @param array<string, array<string, mixed>> $config */
    private function runController(array $config = []): ListReverseZonesController
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
        $this->assertSame('list_reverse_zones.html', $this->output->rendered[0][0]);

        $this->granted = [Permission::PERM_ZONE_CONTENT_VIEW_OTHERS];
        $this->runController();
        $this->assertSame('list_reverse_zones.html', $this->output->rendered[0][0]);
    }

    public function testWithoutEitherViewPermissionThePageIsRefusedBeforeAnyQuery(): void
    {
        $this->granted = [];
        $this->dnsData->expects($this->never())->method('countZones');

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertSame('You do not have sufficient permissions to view this page.', $halt->target);
    }

    public function testPermViewNoneStopsBeforeTheZonesAreFetched(): void
    {
        // Unlike the forward list, which errors only after building the rows,
        // this page checks "no zones at all" before querying them.
        $this->viewLevel = 'none';
        $this->dnsData->expects($this->never())->method('getReverseZones');

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('You do not have the permission to see any zones.', $halt->target);
    }

    public function testThereIsNoSyncActionOnThisPage(): void
    {
        // The same POST that syncs on the forward list just lists here.
        $this->post(['action' => 'sync']);
        $controller = $this->runController(['dns' => ['backend' => 'api']]);

        $this->assertSame('list_reverse_zones.html', $this->output->rendered[0][0]);
    }

    // ------------------------------------------------------------- counting

    public function testCountsAreTakenForReverseZonesOnlyAndNeverPerLetter(): void
    {
        $seen = [];
        $this->dnsData = $this->createMock(\Poweradmin\Application\Service\Backend\DnsDataService::class);
        $this->dnsData->method('countZones')->willReturnCallback(
            function (string $perm, string $letter = 'all', string $type = 'forward') use (&$seen): int {
                $seen[] = [$perm, $letter, $type];
                return 3;
            }
        );
        $this->dnsData->method('getReverseZones')->willReturn([]);
        $this->dnsData->method('getReverseZoneCounts')->willReturnCallback(fn(): array => $this->reverseZoneCounts);
        $this->factory = $this->createMock(\Poweradmin\Application\Service\ControllerServiceFactory::class);
        $this->rewireFactory();

        $this->query(['letter' => 'q']);
        $this->runController();
        $params = $this->renderedParams();

        $this->assertSame([
            ['all', 'all', 'reverse'],
            ['all', 'all', 'reverse'],
            ['all', 'all', 'reverse'],
        ], $seen);
        // The letter filter of the forward list has no counterpart here
        $this->assertArrayNotHasKey('letter_start', $params);
        $this->assertArrayNotHasKey('letters', $params);
        $this->assertFalse($this->session->has(SessionKeys::LETTER));
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
        $this->dnsData->expects($this->once())->method('getReverseZones')
            ->with('all', self::USER_ID, 'all', $expectedOffset, 10)
            ->willReturn([]);

        $this->runController();
    }

    public function testRequestedRowsPerPageWinsOverTheStoredPreference(): void
    {
        $this->query(['rows_per_page' => '25']);

        $this->runController();
        $this->assertSame(25, $this->renderedParams()['iface_rowamount']);
    }

    public function testPaginationCarriesTheFilterAndThePageSizeOnce(): void
    {
        // The presenter appends rows_per_page itself, so the page passes only the filter
        $this->reverseZoneCounts = ['count_all' => 100, 'count_ipv4' => 60, 'count_ipv6' => 40];
        $this->query(['rows_per_page' => '20', 'reverse_type' => 'ipv4']);

        $this->runController();
        $pagination = $this->renderedParams()['pagination'];

        $this->assertStringContainsString('/zones/reverse?start=1&reverse_type=ipv4&rows_per_page=20"', $pagination);
        $this->assertStringNotContainsString('rows_per_page=20&rows_per_page=20', $pagination);
    }

    // ----------------------------------------------------- reverse-type filter

    /** @return array<string, array{0: string|null, 1: string, 2: int}> */
    public static function reverseTypeProvider(): array
    {
        return [
            'nothing submitted' => [null, 'all', 100],
            'ipv4' => ['ipv4', 'ipv4', 60],
            'ipv6' => ['ipv6', 'ipv6', 40],
            'unknown value keeps everything' => ['ipv5', 'all', 100],
        ];
    }

    #[DataProvider('reverseTypeProvider')]
    public function testReverseTypeFilterSelectsTheZonesAndThePaginationCount(
        ?string $submitted,
        string $expectedFilter,
        int $expectedCount
    ): void {
        $this->reverseZoneCounts = ['count_all' => 100, 'count_ipv4' => 60, 'count_ipv6' => 40];
        if ($submitted !== null) {
            $this->query(['reverse_type' => $submitted]);
        }
        $this->dnsData->expects($this->once())->method('getReverseZones')
            ->with('all', self::USER_ID, $expectedFilter)->willReturn([]);

        $this->runController();
        $params = $this->renderedParams();

        $this->assertSame($expectedFilter, $params['reverse_zone_type']);
        $this->assertStringContainsString(
            'start=' . (int)ceil($expectedCount / 10),
            $params['pagination'],
            'the last page link reflects the filtered count'
        );
    }

    public function testTheReverseTypeFilterIsRememberedAcrossRequests(): void
    {
        $this->query(['reverse_type' => 'ipv6']);
        $this->runController();

        $this->query([]);
        $this->runController();
        $this->assertSame('ipv6', $this->renderedParams()['reverse_zone_type']);
    }

    public function testAnUnknownReverseTypeLeavesTheRememberedOneAlone(): void
    {
        $this->session->set(SessionKeys::REVERSE_ZONE_TYPE, 'ipv6');
        $this->query(['reverse_type' => 'nonsense']);

        $this->runController();
        $this->assertSame('ipv6', $this->renderedParams()['reverse_zone_type']);
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
    public function testSortColumnIsCheckedAgainstTheSameAllowListAsTheForwardList(?string $submitted, string $expected): void
    {
        if ($submitted !== null) {
            $this->query(['zone_sort_by' => $submitted]);
        }

        $this->runController();
        $this->assertSame($expected, $this->renderedParams()['zone_sort_by']);
    }

    public function testOwnerSortIsDroppedWhenOwnershipIsOnlyVisibleForOwnZones(): void
    {
        $this->ownershipViewLevel = 'own';
        $this->query(['zone_sort_by' => 'owner']);

        $this->runController();
        $params = $this->renderedParams();

        $this->assertFalse($params['is_owner_sort_supported']);
        $this->assertSame('name', $params['zone_sort_by']);
    }

    public function testGroupSortIsUnavailableOnAnApiBackend(): void
    {
        $this->query(['zone_sort_by' => 'group']);
        $this->runController();
        $this->assertSame('group', $this->renderedParams()['zone_sort_by']);

        $this->runController(['dns' => ['backend' => 'api']]);
        $this->assertSame('name', $this->renderedParams()['zone_sort_by']);
    }

    public function testSortingByNameIsReorderedInPhpUnlikeTheForwardList(): void
    {
        // Only this page re-sorts the fetched page with the configured
        // reverse_zone_sort; the forward list trusts the query's ORDER BY.
        $this->zones = [
            ['id' => '1', 'name' => '10.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []],
            ['id' => '2', 'name' => '2.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []],
        ];

        $this->runController();
        $names = array_column($this->renderedParams()['zones'], 'name');

        $this->assertSame(['2.10.10.in-addr.arpa', '10.10.10.in-addr.arpa'], $names);
    }

    public function testSortingByAnotherColumnLeavesTheFetchedOrderAlone(): void
    {
        $this->zones = [
            ['id' => '1', 'name' => '10.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []],
            ['id' => '2', 'name' => '2.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []],
        ];
        $this->query(['zone_sort_by' => 'type']);

        $this->runController();
        $names = array_column($this->renderedParams()['zones'], 'name');

        $this->assertSame(['10.10.10.in-addr.arpa', '2.10.10.in-addr.arpa'], $names);
    }

    // ------------------------------------------------------------ zone rows

    public function testIpv6ReverseZoneNamesAreShortenedForDisplay(): void
    {
        $longName = '0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';
        $this->zones = [[
            'id' => '1',
            'name' => $longName,
            'utf8_name' => $longName,
            'owners' => [],
            'full_names' => [],
        ]];

        $this->runController();
        $zone = $this->renderedParams()['zones'][0];

        $this->assertSame('2001:db8::', $zone['utf8_name']);
        // Only the display copy is shortened; the real name is untouched
        $this->assertSame($longName, $zone['name']);
    }

    public function testEachRowCarriesItsOwnDeleteEligibility(): void
    {
        $this->deleteLevel = 'own';
        $this->zones = [
            ['id' => '1', 'name' => '1.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []],
            ['id' => '2', 'name' => '2.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []],
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
            ['id' => '1', 'name' => '1.10.10.in-addr.arpa', 'owners' => ['tester'], 'full_names' => ['Tess Ter']],
            ['id' => '2', 'name' => '2.10.10.in-addr.arpa', 'owners' => ['other'], 'full_names' => ['Other Person']],
        ];
        $this->ownership = new ZoneOwnershipIndex(self::USER_ID, [], [1 => [self::USER_ID]], [1 => [7], 2 => [7]]);

        $this->runController();
        $zones = $this->renderedParams()['zones'];

        $this->assertSame(['tester'], $zones[0]['owners']);
        $this->assertSame(['netops'], $zones[0]['groups']);
        $this->assertSame([], $zones[1]['owners']);
        $this->assertSame([], $zones[1]['groups']);
    }

    public function testForwardZoneAssociationsCanBeSwitchedOff(): void
    {
        $this->zones = [['id' => '1', 'name' => '1.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []]];
        $this->zoneRepository->expects($this->never())->method('findForwardZonesByPtrRecords');

        $this->runController(['interface' => ['show_forward_zone_associations' => false]]);
        $params = $this->renderedParams();

        $this->assertSame([], $params['associated_forward_zones']);
        $this->assertFalse($params['show_forward_zone_associations']);
    }

    public function testForwardZoneAssociationsAreResolvedWhenEnabled(): void
    {
        $this->zones = [['id' => '1', 'name' => '1.10.10.in-addr.arpa', 'owners' => [], 'full_names' => []]];
        $this->ptrMatches = [[
            'reverse_domain_id' => '1',
            'forward_domain_id' => '9',
            'forward_domain_name' => 'example.com',
            'ptr_content' => 'host.example.com',
        ]];
        $this->zoneRepository->expects($this->once())->method('findForwardZonesByPtrRecords')->with(['1']);

        $this->runController();
        $params = $this->renderedParams();

        $this->assertSame([['id' => '9', 'name' => 'example.com', 'ptr_records' => 1]], $params['associated_forward_zones']['1']);
    }

    private function rewireFactory(): void
    {
        $this->factory->method('permissionService')->willReturn($this->permissions);
        $this->factory->method('userPreferenceService')->willReturn($this->preferences);
        $this->factory->method('paginationService')
            ->willReturn(new \Poweradmin\Application\Service\Web\PaginationService($this->preferences));
        $this->factory->method('dnsDataService')->willReturn($this->dnsData);
        $this->factory->method('zoneListPermissionService')->willReturn($this->zoneListPermissions);
        $this->factory->method('userGroupRepository')->willReturn($this->userGroups);
        $this->factory->method('zoneRepository')->willReturn($this->zoneRepository);
    }

    private function haltOf(ListReverseZonesController $controller): RequestHalted
    {
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }
}
