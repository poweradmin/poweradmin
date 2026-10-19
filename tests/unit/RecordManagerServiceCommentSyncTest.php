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


namespace unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

/**
 * Comment sync derives the related zone from the record content, so it must
 * only reach a zone the user may edit. The record's own comment is still kept.
 */
class RecordManagerServiceCommentSyncTest extends TestCase
{
    private const OWN_ZONE_ID = 10;
    private const RELATED_ZONE_ID = 20;

    public function testARecordDoesNotSyncIntoAReverseZoneTheUserCannotEdit(): void
    {
        $dnsRecord = $this->dnsRecord(relatedEditable: false);
        $dnsRecord->method('get_best_matching_zone_id_from_name')->willReturn(self::RELATED_ZONE_ID);

        $sync = $this->createMock(RecordCommentSyncService::class);
        $sync->expects($this->never())->method('syncCommentsForPtrRecord');

        $comments = $this->createMock(RecordCommentService::class);
        $comments->expects($this->once())
            ->method('createComment')
            ->with(self::OWN_ZONE_ID, 'www.example.com', 'A', 'note', 'user');

        $this->service($dnsRecord, $comments, $sync)
            ->createRecord(self::OWN_ZONE_ID, 'www', 'A', '192.0.2.1', 3600, 0, 'note', 'user', '127.0.0.1');
    }

    public function testARecordSyncsIntoAReverseZoneTheUserCanEdit(): void
    {
        $dnsRecord = $this->dnsRecord(relatedEditable: true);
        $dnsRecord->method('get_best_matching_zone_id_from_name')->willReturn(self::RELATED_ZONE_ID);

        $sync = $this->createMock(RecordCommentSyncService::class);
        $sync->expects($this->once())->method('syncCommentsForPtrRecord');

        $this->service($dnsRecord, $this->createMock(RecordCommentService::class), $sync)
            ->createRecord(self::OWN_ZONE_ID, 'www', 'A', '192.0.2.1', 3600, 0, 'note', 'user', '127.0.0.1');
    }

    public function testPtrRecordDoesNotSyncIntoAForwardZoneTheUserCannotEdit(): void
    {
        $dnsRecord = $this->dnsRecord(relatedEditable: false);
        $dnsRecord->method('get_domain_id_by_name')->willReturn(self::RELATED_ZONE_ID);

        $sync = $this->createMock(RecordCommentSyncService::class);
        $sync->expects($this->never())->method('syncCommentsForDomainRecord');

        $comments = $this->createMock(RecordCommentService::class);
        $comments->expects($this->once())
            ->method('createComment')
            ->with(self::OWN_ZONE_ID, '1.example.com', 'PTR', 'note', 'user');

        $this->service($dnsRecord, $comments, $sync)
            ->createRecord(self::OWN_ZONE_ID, '1', 'PTR', 'host.victim.example', 3600, 0, 'note', 'user', '127.0.0.1');
    }

    private function dnsRecord(bool $relatedEditable): DnsRecord&MockObject
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('get_domain_name_by_id')->willReturn('example.com');
        $dnsRecord->method('add_record')->willReturn(true);
        $dnsRecord->method('user_can_edit_zone')->with(self::RELATED_ZONE_ID)->willReturn($relatedEditable);

        return $dnsRecord;
    }

    private function service(DnsRecord $dnsRecord, RecordCommentService $comments, RecordCommentSyncService $sync): RecordManagerService
    {
        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturnCallback(fn(string $name): mixed => $name === 'record_comments_sync');

        return new RecordManagerService(
            $this->createMock(PDOLayer::class),
            $dnsRecord,
            $comments,
            $sync,
            $this->createMock(LegacyLogger::class),
            $config
        );
    }
}
