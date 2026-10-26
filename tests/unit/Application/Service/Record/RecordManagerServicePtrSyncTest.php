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

use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use Poweradmin\Domain\Model\Permission;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;

/**
 * With record comment sync on, a comment on a new A record is copied to the PTR
 * records of the matching reverse zone, and a PTR comment to the matching A
 * records; the records are read from the shared record repository, so a PTR
 * written earlier in the same request is found.
 */
class RecordManagerServicePtrSyncTest extends PermissionServiceTestCase
{
    private const ADMIN = 3;
    private const OWNER = 4;
    private PDO $db;
    private RecordCommentService&MockObject $comments;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER, ordername TEXT, auth INTEGER)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (50, 5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 3600, 0, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (60, 1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (61, 1, 'host.example.com', 'AAAA', '2001:db8::1', 3600, 0, 0)");
        $this->comments = $this->createMock(RecordCommentService::class);
    }

    /** @param int[] $slaveZoneIds */
    private function service(bool $sync = true, array $slaveZoneIds = [], ?ChangeApprovalContext $approval = null): RecordManagerService
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturnMap([[1, 'example.com'], [5, '2.0.192.in-addr.arpa']]);
        $domains->method('getBestMatchingZoneIdFromName')->willReturnCallback(fn(string $name) => str_ends_with($name, '.in-addr.arpa') ? 5 : -1);
        $domains->method('getDomainIdByName')->willReturnCallback(fn(string $name) => $name === 'example.com' ? 1 : null);
        $domains->method('getDomainType')->willReturnCallback(fn(int $id) => in_array($id, $slaveZoneIds, true) ? 'SLAVE' : 'MASTER');

        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('addRecordGetId')->willReturn(RecordWriteResult::ok(99));

        $config = new FakeConfiguration(['misc' => ['record_comments_sync' => $sync], 'database' => ['type' => 'sqlite', 'pdns_db_name' => '']]);

        $permissions = $this->buildPermissionService(
            permissionsByUser: [self::OWNER => [Permission::PERM_ZONE_CONTENT_EDIT_OWN]],
            adminUserIds: [self::ADMIN],
            ownedZonesByUser: [self::OWNER => [1]]
        );

        return new RecordManagerService($domains, new SqlRecordRepository($this->db, $config), $manager, $this->comments, $this->createMock(AuditService::class), $config, $permissions, $approval);
    }

    public function testACommentIsCopiedToThePtrRecordsOfTheReverseZone(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'admin', userId: self::ADMIN);

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

        $this->service()->createRecord(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 3600, 0, 'shared note', 'admin', userId: self::ADMIN);

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

        $service->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'admin', userId: self::ADMIN);

        $this->assertSame([99, 50, 51], $targets);
    }

    public function testCompanionSyncCopiesTheCommentToTheARecordCreatedAfterThePtr(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 'rack 4', 'admin', self::ADMIN);

        $this->assertSame([[1, 'host.example.com', 'A', 'rack 4', 60, 'admin']], $calls);
    }

    public function testCompanionSyncCopiesTheCommentToThePtrRecordCreatedAfterTheA(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->syncCompanionComment(1, 'host.example.com', 'A', '192.0.2.1', 'rack 4', 'admin', self::ADMIN);

        $this->assertSame([[5, '1.2.0.192.in-addr.arpa', 'PTR', 'rack 4', 50, 'admin']], $calls);
    }

    public function testCompanionSyncDoesNothingWhenSyncIsOffOrTheCommentIsEmpty(): void
    {
        $this->comments->expects($this->never())->method('createCommentForRecord');

        $this->service(false)->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 'rack 4', 'admin', self::ADMIN);
        $this->service()->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', '', 'admin', self::ADMIN);
    }

    public function testAnIp6ArpaPtrCommentGoesToTheAaaaRecordsOnly(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });
        $ptrName = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa';

        $this->service()->syncCompanionComment(7, $ptrName, 'PTR', 'host.example.com', 'rack 4', 'admin', self::ADMIN);

        $this->assertSame([[1, 'host.example.com', 'AAAA', 'rack 4', 61, 'admin']], $calls);
    }

    public function testAPtrToAZoneApexCopiesTheCommentToTheApexRecord(): void
    {
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (62, 1, 'example.com', 'A', '192.0.2.9', 3600, 0, 0)");
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->syncCompanionComment(5, '9.2.0.192.in-addr.arpa', 'PTR', 'example.com', 'apex', 'admin', self::ADMIN);

        $this->assertSame([[1, 'example.com', 'A', 'apex', 62, 'admin']], $calls);
    }

    public function testACommentIsNotCopiedToAReverseZoneTheUserCannotEdit(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'owner', userId: self::OWNER);

        $this->assertSame([[1, 'host.example.com', 'A', 'shared note', 99, 'owner']], $calls, 'only the own record gets the comment');
    }

    public function testAPtrCommentIsNotCopiedToAForwardZoneTheUserCannotEdit(): void
    {
        $this->comments->expects($this->never())->method('createCommentForRecord');

        $this->service()->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 'rack 4', 'helper', 99);
    }

    public function testACommentIsCopiedToAnOwnedForwardZone(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 'rack 4', 'owner', self::OWNER);

        $this->assertSame([[1, 'host.example.com', 'A', 'rack 4', 60, 'owner']], $calls);
    }

    public function testACommentIsNotCopiedIntoASecondaryZone(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service(true, [5])->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'admin', userId: self::ADMIN);

        $this->assertSame([[1, 'host.example.com', 'A', 'shared note', 99, 'admin']], $calls);
    }

    public function testWithoutAnActingUserOnlyTheOwnRecordGetsTheComment(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'admin');

        $this->assertSame([[1, 'host.example.com', 'A', 'shared note', 99, 'admin']], $calls);
    }

    public function testACommentIsNotCopiedToAReverseZoneWhereTheUserMustFileAChangeRequest(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });
        $approval = $this->createMock(ChangeApprovalContext::class);
        $approval->method('modeForZone')->willReturnCallback(
            fn(?int $userId, int $zoneId): string => $zoneId === 5 ? ChangeApprovalPolicy::MODE_REQUEST : ChangeApprovalPolicy::MODE_DIRECT
        );

        $this->service(approval: $approval)->createRecord(1, 'host.example.com', 'A', '192.0.2.1', 3600, 0, 'shared note', 'admin', userId: self::ADMIN);

        $this->assertSame([[1, 'host.example.com', 'A', 'shared note', 99, 'admin']], $calls);
    }

    public function testACommentIsNotCopiedToAZoneOutsideTheCallersLimit(): void
    {
        $this->comments->expects($this->never())->method('createCommentForRecord');

        $this->service()->syncCompanionComment(1, 'host.example.com', 'A', '192.0.2.1', 'rack 4', 'admin', self::ADMIN, fn(int $zoneId): bool => $zoneId !== 5);
    }
}
