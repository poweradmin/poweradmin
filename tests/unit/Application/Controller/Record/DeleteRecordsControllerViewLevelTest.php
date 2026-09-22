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

namespace Poweradmin\Tests\Unit\Application\Controller\Record;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Record\DeleteRecordsController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\RecordBatchDeletionOutcome;
use Poweradmin\Domain\Service\Dns\RecordDeletionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The bulk delete page consults the zone view gate before every zone lookup:
 * without a view level the lookup is skipped, the refusal is flashed, and the
 * page falls back to the search redirect and the type-less record row.
 */
#[CoversClass(DeleteRecordsController::class)]
class DeleteRecordsControllerViewLevelTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;
    private const RECORD_ID = 34;

    private string $viewLevel = 'all';

    /** @var DomainRepositoryInterface&MockObject */
    private DomainRepositoryInterface $domains;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getEditPermissionLevelForZone')->willReturn('all');
        $permissions->method('getViewPermissionLevel')->willReturnCallback(fn(): string => $this->viewLevel);

        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getZoneIdFromRecordId')->willReturn(self::ZONE_ID);
        $records->method('recidToDomid')->willReturn(self::ZONE_ID);
        $records->method('getRecordFromId')->willReturn([
            'id' => self::RECORD_ID,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
        ]);

        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->domains->method('getZoneInfoFromId')->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);
        $this->domains->method('getDomainNameById')->willReturn('example.com');

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('deleteRecord')->willReturn(RecordWriteResult::ok());

        $changeLog = $this->createMock(RecordChangeLogger::class);
        $changeLog->method('withChangeset')->willReturnCallback(fn(?int $zoneId, ?string $comment, callable $work): mixed => $work());
        $changeLog->method('changeCommentRequired')->willReturn(false);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('recordRepository')->willReturn($records);
        $this->factory->method('domainRepository')->willReturn($this->domains);
        $this->factory->method('recordManager')->willReturn($recordManager);
        $this->factory->method('recordChangeLog')->willReturn($changeLog);
        $this->factory->method('reverseRecordCreator')->willReturn($this->createMock(ReverseRecordCreator::class));
        $this->factory->method('auditService')->willReturn($this->createMock(AuditService::class));
        $deletion = $this->createMock(RecordDeletionService::class);
        $deletion->method('deleteMany')->willReturn(new RecordBatchDeletionOutcome(1, []));
        $this->factory->method('recordDeletionService')->willReturn($deletion);
    }

    private function makeController(): TestableDeleteRecordsController
    {
        return new TestableDeleteRecordsController($_POST, $this->environment($this->configure()));
    }

    public function testAConfirmedDeleteFromTheZonePageReturnsToTheZone(): void
    {
        $this->post(['record_id' => [(string)self::RECORD_ID], 'confirm' => '1', 'zone_id' => (string)self::ZONE_ID]);

        try {
            $this->makeController()->run();
            $this->fail('Expected a redirect.');
        } catch (RequestHalted $halt) {
            $this->assertSame('/zones/12/edit', $halt->target);
        }

        $this->assertSame([], $this->messagesFor('system'));
        $this->assertCount(1, $this->messagesFor('edit'));
    }

    public function testWithoutAnyViewLevelAConfirmedDeleteFallsBackToSearchAndFlashesTheRefusal(): void
    {
        $this->viewLevel = 'none';
        $this->domains->expects($this->never())->method('getZoneInfoFromId');
        $this->post(['record_id' => [(string)self::RECORD_ID], 'confirm' => '1', 'zone_id' => (string)self::ZONE_ID]);

        try {
            $this->makeController()->run();
            $this->fail('Expected a redirect.');
        } catch (RequestHalted $halt) {
            $this->assertSame('/search', $halt->target);
        }

        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
        $this->assertCount(1, $this->messagesFor('search'));
    }

    public function testWithoutAnyViewLevelTheConfirmationPageStillListsTheRecordAndFlashesTheRefusal(): void
    {
        $this->viewLevel = 'none';
        $this->domains->expects($this->never())->method('getZoneInfoFromId');
        $this->post(['record_id' => [(string)self::RECORD_ID]]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame('delete_records.html', $controller->rendered[0][0]);
        $this->assertSame(1, $controller->rendered[0][1]['total_records']);
        $this->assertSame([['error', 'You do not have permission to view this zone.']], $this->messagesFor('system'));
    }
}
