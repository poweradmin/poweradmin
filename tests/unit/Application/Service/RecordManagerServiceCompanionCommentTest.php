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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

/**
 * The companion A or PTR record is written after the record it mirrors, so its
 * comment is synced in a second step once it exists.
 */
class RecordManagerServiceCompanionCommentTest extends TestCase
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

    private function service(bool $sync = true): RecordManagerService
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getBestMatchingZoneIdFromName')->willReturnCallback(fn(string $name) => str_ends_with($name, '.in-addr.arpa') ? 5 : -1);
        $domains->method('getDomainIdByName')->willReturnCallback(fn(string $name) => $name === 'example.com' ? 1 : null);
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key) => match ("$group.$key") {
            'misc.record_comments_sync' => $sync,
            'database.type' => 'sqlite',
            'database.pdns_db_name' => '',
            default => null,
        });
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(false);

        return new RecordManagerService(
            $this->db,
            $domains,
            $this->createMock(RecordManagerInterface::class),
            $this->comments,
            $this->createMock(LegacyLogger::class),
            $config,
            $backend
        );
    }

    public function testCopiesThePtrCommentToTheARecordCreatedAfterIt(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 'rack 4', 'admin');

        $this->assertSame([[1, 'host.example.com', 'A', 'rack 4', 60, 'admin']], $calls);
    }

    public function testCopiesTheACommentToThePtrRecordCreatedAfterIt(): void
    {
        $calls = [];
        $this->comments->method('createCommentForRecord')->willReturnCallback(function (...$args) use (&$calls) {
            $calls[] = $args;
            return null;
        });

        $this->service()->syncCompanionComment(1, 'host.example.com', 'A', '192.0.2.1', 'rack 4', 'admin');

        $this->assertSame([[5, '1.2.0.192.in-addr.arpa', 'PTR', 'rack 4', 50, 'admin']], $calls);
    }

    public function testDoesNothingWhenSyncIsOffOrTheCommentIsEmpty(): void
    {
        $this->comments->expects($this->never())->method('createCommentForRecord');

        $this->service(false)->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 'rack 4', 'admin');
        $this->service()->syncCompanionComment(5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', '', 'admin');
    }
}
