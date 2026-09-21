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
use Poweradmin\Application\Controller\DatabaseConsistencyController;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Service\Consistency\SqlConsistencyChecks;
use RuntimeException;

/**
 * Characterizes the fix actions of the database consistency page: which repair
 * each check type and action reaches, the message flashed for its outcome, and
 * the single redirect that follows every one of them.
 *
 * The checker is a real strategy with only its repairs stubbed, so fixOne() and
 * fixAll() run for real and the messages stay pinned end to end.
 */
#[CoversClass(DatabaseConsistencyController::class)]
class DatabaseConsistencyControllerTest extends SeamControllerTestCase
{
    private const REDIRECT = '/tools/database-consistency';

    /** @var SqlConsistencyChecks&MockObject */
    private SqlConsistencyChecks $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $this->checker = $this->getMockBuilder(SqlConsistencyChecks::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'fixZoneWithoutOwner',
                'fixAllZonesWithoutOwner',
                'fixZoneCanonicalId',
                'fixAllZonesWithCanonicalIdIssue',
                'deleteSlaveZone',
                'deleteOrphanedRecord',
                'fixDuplicateSOA',
                'createDefaultSOA',
            ])
            ->getMock();

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('consistencyChecker')->willReturn($this->checker);
    }

    private function fixRequest(string $checkType, string $action, ?string $itemId = '7'): TestableDatabaseConsistencyController
    {
        $fields = ['check_type' => $checkType, 'action' => $action];
        if ($itemId !== null) {
            $fields['item_id'] = $itemId;
        }
        $this->post($fields);

        $controller = new TestableDatabaseConsistencyController(
            $_POST,
            true,
            $this->environment($this->configure(['interface' => ['enable_consistency_checks' => true]]))
        );

        $controller->run();

        return $controller;
    }

    /** @param list<array{0: string, 1: string}> $expected */
    private function assertFlashed(array $expected, TestableDatabaseConsistencyController $controller): void
    {
        $this->assertSame([self::REDIRECT], $controller->redirects);
        $this->assertSame([], $controller->rendered);
        $this->assertSame($expected, $this->messagesFor('database_consistency'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    public static function singleItemProvider(): array
    {
        return [
            'owner' => ['zones_without_owners', 'fix', 'fixZoneWithoutOwner', 'Zone owner assigned successfully', 'Failed to assign zone owner'],
            'canonical id' => ['zones_without_canonical_ids', 'fix', 'fixZoneCanonicalId', 'Zone canonical ID repaired', 'Failed to repair zone canonical ID'],
            'slave zone' => ['slave_zones_without_masters', 'delete', 'deleteSlaveZone', 'Slave zone deleted successfully', 'Failed to delete slave zone'],
            'orphaned record' => ['orphaned_records', 'delete', 'deleteOrphanedRecord', 'Orphaned record deleted successfully', 'Failed to delete orphaned record'],
            'duplicate soa' => ['duplicate_soa', 'fix', 'fixDuplicateSOA', 'Duplicate SOA records fixed successfully', 'Failed to fix duplicate SOA records'],
            'missing soa' => ['zones_without_soa', 'fix', 'createDefaultSOA', 'Default SOA record created successfully', 'Failed to create default SOA record'],
        ];
    }

    #[DataProvider('singleItemProvider')]
    public function testASuccessfulSingleItemFixFlashesItsSuccessMessage(string $checkType, string $action, string $method, string $success, string $failure): void
    {
        $this->checker->expects($this->once())->method($method)->with(7)->willReturn(true);

        $this->assertFlashed([['success', $success]], $this->fixRequest($checkType, $action));
    }

    #[DataProvider('singleItemProvider')]
    public function testAFailedSingleItemFixFlashesItsFailureMessage(string $checkType, string $action, string $method, string $success, string $failure): void
    {
        $this->checker->method($method)->willReturn(false);

        $this->assertFlashed([['error', $failure]], $this->fixRequest($checkType, $action));
    }

    public function testTheOwnerFixAssignsTheLoggedInUser(): void
    {
        $this->checker->expects($this->once())->method('fixZoneWithoutOwner')->with(7, self::USER_ID)->willReturn(true);

        $this->fixRequest('zones_without_owners', 'fix');
    }

    /** @return array<string, array{0: array{assigned: int, failed: int}, 1: string, 2: string}> */
    public static function ownerFixAllProvider(): array
    {
        return [
            'nothing to fix' => [['assigned' => 0, 'failed' => 0], 'success', 'No zones without owners to fix'],
            'all assigned' => [['assigned' => 3, 'failed' => 0], 'success', 'Assigned ownership of 3 zones'],
            'partly failed' => [['assigned' => 2, 'failed' => 1], 'warning', 'Assigned 2 zones; 1 failed'],
        ];
    }

    /** @param array{assigned: int, failed: int} $counts */
    #[DataProvider('ownerFixAllProvider')]
    public function testFixingAllOwnersReportsTheTally(array $counts, string $type, string $message): void
    {
        $this->checker->expects($this->once())->method('fixAllZonesWithoutOwner')->with(self::USER_ID)->willReturn($counts);

        $this->assertFlashed([[$type, $message]], $this->fixRequest('zones_without_owners', 'fix_all', null));
    }

    /** @return array<string, array{0: array{fixed: int, failed: int}, 1: string, 2: string}> */
    public static function canonicalIdFixAllProvider(): array
    {
        return [
            'nothing to fix' => [['fixed' => 0, 'failed' => 0], 'success', 'No zones without a canonical ID to fix'],
            'all repaired' => [['fixed' => 4, 'failed' => 0], 'success', 'Repaired the canonical ID of 4 zones'],
            'partly failed' => [['fixed' => 1, 'failed' => 2], 'warning', 'Repaired 1 zones; 2 failed'],
        ];
    }

    /** @param array{fixed: int, failed: int} $counts */
    #[DataProvider('canonicalIdFixAllProvider')]
    public function testFixingAllCanonicalIdsReportsTheTally(array $counts, string $type, string $message): void
    {
        $this->checker->expects($this->once())->method('fixAllZonesWithCanonicalIdIssue')->willReturn($counts);

        $this->assertFlashed([[$type, $message]], $this->fixRequest('zones_without_canonical_ids', 'fix_all', null));
    }

    public function testAnUnknownCheckTypeIsRejected(): void
    {
        $this->assertFlashed([['error', 'Invalid check type']], $this->fixRequest('something_else', 'fix_all'));
        $this->assertFlashed([['error', 'Invalid action']], $this->fixRequest('something_else', 'fix'));
    }

    public function testAnActionThatDoesNotMatchTheCheckTypeRunsNoRepair(): void
    {
        foreach (['deleteSlaveZone', 'fixZoneWithoutOwner', 'fixAllZonesWithoutOwner', 'deleteOrphanedRecord'] as $repair) {
            $this->checker->expects($this->never())->method($repair);
        }

        $this->assertFlashed([['error', 'Invalid action']], $this->fixRequest('slave_zones_without_masters', 'fix'));
        $this->assertFlashed([['error', 'Invalid action']], $this->fixRequest('zones_without_owners', 'delete'));
        $this->assertFlashed([['error', 'Invalid action']], $this->fixRequest('orphaned_records', ''));
    }

    public function testFixingAllIsRejectedForACheckTypeWithoutABulkRepair(): void
    {
        $this->checker->expects($this->never())->method('deleteSlaveZone');

        $this->assertFlashed([['error', 'Invalid check type']], $this->fixRequest('slave_zones_without_masters', 'fix_all', null));
    }

    public function testAMissingItemIdReachesTheRepairAsZero(): void
    {
        $this->checker->expects($this->once())->method('fixZoneWithoutOwner')->with(0, self::USER_ID)->willReturn(false);

        $this->assertFlashed([['error', 'Failed to assign zone owner']], $this->fixRequest('zones_without_owners', 'fix', null));
    }

    public function testAnExceptionFromTheCheckerIsFlashedAsAnError(): void
    {
        $this->checker->method('deleteSlaveZone')->willThrowException(new RuntimeException('API unreachable'));

        $this->assertFlashed([['error', 'API unreachable']], $this->fixRequest('slave_zones_without_masters', 'delete'));
    }
}
