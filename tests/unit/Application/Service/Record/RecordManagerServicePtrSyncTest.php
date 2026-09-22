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

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use TestHelpers\FakeConfiguration;

/**
 * With record comment sync on, a comment on a new A record is copied to the PTR
 * records of the matching reverse zone, and a PTR comment to the matching A
 * records; the records are read from the shared record repository, so a PTR
 * written earlier in the same request is found.
 */
class RecordManagerServicePtrSyncTest extends TestCase
{
    private PDO $db;
    private RecordCommentService&MockObject $comments;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER, ordername TEXT, auth INTEGER)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (50, 5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 3600, 0, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (60, 1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 0)");
        $this->comments = $this->createMock(RecordCommentService::class);
    }

    private function service(): RecordManagerService
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturnMap([[1, 'example.com'], [5, '2.0.192.in-addr.arpa']]);
        $domains->method('getBestMatchingZoneIdFromName')->willReturnCallback(fn(string $name) => str_ends_with($name, '.in-addr.arpa') ? 5 : -1);
        $domains->method('getDomainIdByName')->willReturnCallback(fn(string $name) => $name === 'example.com' ? 1 : null);

        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(99));

        $config = new FakeConfiguration(['misc' => ['record_comments_sync' => true], 'database' => ['type' => 'sqlite', 'pdns_db_name' => '']]);

        return new RecordManagerService($domains, new SqlRecordRepository($this->db, $config), $manager, $this->comments, $this->createMock(AuditService::class), $config);
    }

    public function testACommentIsCopiedToThePtrRecordsOfTheReverseZone(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'admin');

        $this->assertSame([
            [1, 'host.example.com', 'A', 'shared note', 99, 'admin'],
            [5, '1.2.0.192.in-addr.arpa', 'PTR', 'shared note', 50, 'admin'],
        ], $calls);
    }

    public function testAPtrCommentIsCopiedToTheMatchingARecords(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->createRecord(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 3600, 0, 'shared note', 'admin');

        $this->assertSame([
            [5, '1.2.0.192.in-addr.arpa', 'PTR', 'shared note', 99, 'admin'],
            [1, 'host.example.com', 'A', 'shared note', 60, 'admin'],
        ], $calls);
    }

    public function testAPtrWrittenAfterConstructionIsStillFound(): void
    {
        $service = $this->service();
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (51, 5, '1.2.0.192.in-addr.arpa', 'PTR', 'other.example.com', 3600, 0, 0)");
        $targets = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (int $zoneId, string $name, string $type, string $comment, int|string $recordId) use (&$targets) {
            $targets[] = $recordId;
            return null;
        });

        $service->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'admin');

        $this->assertSame([99, 50, 51], $targets);
    }
}
