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

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use TestHelpers\SqliteIntegrationTestCase;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Session\SessionActor;

/**
 * Deleting a record takes its template link and comments with it and, unless a
 * batch caller finalises the zone itself, bumps the serial.
 */
class RecordManagerDeleteRecordCleanupTest extends SqliteIntegrationTestCase
{
    private const ZONE_ID = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        foreach (
            [
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)",
                "CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, modified_at INTEGER, account TEXT, comment TEXT)",
                "CREATE TABLE record_comment_links (record_id TEXT PRIMARY KEY, comment_id INTEGER UNIQUE)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (" . self::ZONE_ID . ", " . self::ADMIN_USER_ID . ")");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (1, " . self::ZONE_ID . ", 'www.example.com', 'A', '192.0.2.1', 3600, 0), (2, " . self::ZONE_ID . ", 'www.example.com', 'A', '192.0.2.2', 3600, 0)");
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (" . self::ZONE_ID . ", 1, 7)");
        $this->db->exec("INSERT INTO comments (id, domain_id, name, type, modified_at, account, comment) VALUES
            (5, " . self::ZONE_ID . ", 'www.example.com', 'A', 0, 'admin', 'first'),
            (6, " . self::ZONE_ID . ", 'www.example.com', 'A', 0, 'admin', 'rrset')");
        $this->db->exec("INSERT INTO record_comment_links (record_id, comment_id) VALUES ('1', 5)");
    }

    #[RunInSeparateProcess]
    public function testTheTemplateLinkAndOwnCommentGoWithTheRecord(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID);

        $this->assertTrue($this->makeRecordManager($soa)->deleteRecord(1)->success);

        $this->assertSame(0, $this->rows('records_zone_templ WHERE record_id = 1'));
        $this->assertSame(0, $this->rows('record_comment_links'));
        // A sibling record still carries the RRset comment
        $this->assertSame([6], array_map('intval', $this->db->query('SELECT id FROM comments')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    #[RunInSeparateProcess]
    public function testTheLastRecordOfAnRRSetTakesTheRRSetCommentAlong(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');
        $manager = $this->makeRecordManager($soa);

        $this->assertTrue($manager->deleteRecord(1, false)->success);
        $this->assertTrue($manager->deleteRecord(2, false)->success);

        $this->assertSame(0, $this->rows('comments'));
    }

    private function makeRecordManager(SOARecordManagerInterface $soa): RecordManager
    {
        $config = $this->sqliteConfiguration(['dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600]]);
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.com');
        $backend = $this->dnsBackendStub(false);
        $backend->method('deleteRecord')->willReturnCallback(function (int|string $id): bool {
            $this->db->exec("DELETE FROM records WHERE id = " . (int)$id);
            return true;
        });

        return new RecordManager(
            $this->db,
            $config,
            $this->createMock(DnsRecordValidationServiceInterface::class),
            $soa,
            $domainRepository,
            new RepositoryFactory($this->db, $config, $backend),
            fn() => $this->createMock(DnssecProviderInterface::class),
            $backend,
            $this->permissionService($config),
            $this->createMock(RecordChangeLogger::class),
            new DbTemplateRecordLinkRepository($this->db, $config, $backend),
            new SessionActor()
        );
    }

    private function rows(string $fromWhere): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $fromWhere")->fetchColumn();
    }
}
