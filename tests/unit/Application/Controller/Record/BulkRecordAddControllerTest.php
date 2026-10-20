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
use Poweradmin\Application\Controller\Record\BulkRecordAddController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\User\UserPreferenceService;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * The bulk add form: how many lines one submission may carry, and what the user
 * gets back when it carries too many.
 */
#[CoversClass(BulkRecordAddController::class)]
final class BulkRecordAddControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    /** @var RecordManagerService&MockObject */
    private RecordManagerService $records;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('getEditPermissionLevel')->willReturn('all');
        $permissions->method('getEditPermissionLevelForZone')->willReturn('all');
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturn('none');
        $permissions->method('userOwnsZone')->willReturn(true);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->willReturn('MASTER');
        $domains->method('getDomainNameById')->willReturn('example.com');

        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('resolveTtlForType')->willReturn(3600);
        $ttl->method('resolveTtlsForTypes')->willReturnCallback(
            static fn(array $types): array => array_fill_keys(array_map('strtoupper', $types), 3600)
        );

        $changeLog = $this->createMock(RecordChangeLogger::class);
        $changeLog->method('changeCommentRequired')->willReturn(false);
        $changeLog->method('withChangeset')->willReturnCallback(static fn(?int $zoneId, ?string $comment, callable $work): mixed => $work());

        $preferences = $this->createMock(UserPreferenceService::class);
        $preferences->method('getDisplayHostnameOnly')->willReturn(false);

        $this->records = $this->createMock(RecordManagerService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('reverseTtlResolver')->willReturn($ttl);
        $this->factory->method('recordChangeLog')->willReturn($changeLog);
        $this->factory->method('recordManagerService')->willReturn($this->records);
        $this->factory->method('userPreferenceService')->willReturn($preferences);
    }

    private function submit(string $records): void
    {
        $this->query(['id' => (string)self::ZONE_ID]);
        $this->post(['records' => $records]);
        $controller = new BulkRecordAddController($this->requestData(), true, $this->environment($this->configure()));

        try {
            $controller->run();
        } catch (RequestHalted) {
            // A successful add redirects to the zone editor
        }
    }

    private static function lines(int $count): string
    {
        return implode("\n", array_map(static fn(int $i): string => "h$i,A,192.0.2." . ($i % 250 + 1), range(1, $count)));
    }

    public function testAListAboveTheCapIsRefusedWholeAndHandedBackForSplitting(): void
    {
        $this->records->expects($this->never())->method('createRecord');
        $text = self::lines(BulkRecordAddController::MAX_LINES + 1);

        $this->submit($text);

        $this->assertSame([['error', sprintf('At most %d records per bulk add; split the list.', BulkRecordAddController::MAX_LINES)]], $this->messagesFor('bulk_record_add'));
        $this->assertSame('bulk_record_add.html', $this->renderedTemplate());
        $this->assertSame($text, $this->renderedParams()['records_text']);
        $this->assertSame([], $this->renderedParams()['failed_records']);
    }

    public function testBlankLinesDoNotCountTowardsTheCap(): void
    {
        $this->records->expects($this->exactly(BulkRecordAddController::MAX_LINES))->method('createRecord')->willReturn(RecordWriteResult::ok(1));

        $this->submit(self::lines(BulkRecordAddController::MAX_LINES) . "\n\n   \n\n");

        $this->assertSame([['success', sprintf('%d record(s) have been added successfully.', BulkRecordAddController::MAX_LINES)]], $this->messagesFor('edit'));
    }
}
