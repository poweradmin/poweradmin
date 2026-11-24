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
namespace Poweradmin\Tests\Unit\Application\Service\Record;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;

/**
 * Bulk add and zone-file import reach createRecord() directly, so it refuses a
 * comment that is not valid UTF-8 like the single-record paths do.
 */
#[CoversClass(RecordManagerService::class)]
final class RecordManagerServiceCommentEncodingTest extends TestCase
{
    private function service(int $expectedWrites): RecordManagerService
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->expects($this->exactly($expectedWrites))->method('addRecordGetId')->willReturn(RecordWriteResult::ok(5));
        $comments = $this->createMock(RecordCommentService::class);
        $comments->expects($this->exactly($expectedWrites))->method('createCommentForRecord');

        return new RecordManagerService(
            $domains,
            $this->createMock(RecordRepositoryInterface::class),
            $manager,
            $comments,
            $this->createMock(AuditService::class),
            $this->createMock(ConfigurationInterface::class),
            $this->createMock(PermissionService::class)
        );
    }

    public function testACommentWithInvalidBytesRefusesTheRecordBeforeAnythingIsWritten(): void
    {
        $result = $this->service(0)->createRecord(1, 'host', 'A', '192.0.2.1', 3600, 0, "bad \xff comment", 'admin');

        $this->assertFalse($result->success);
        $this->assertSame('The comment field contains invalid characters.', $result->message);
    }

    public function testAValidMultibyteCommentIsStored(): void
    {
        $this->assertTrue($this->service(1)->createRecord(1, 'host', 'A', '192.0.2.1', 3600, 0, 'sveiki, pasauli ąčę', 'admin')->success);
    }
}
