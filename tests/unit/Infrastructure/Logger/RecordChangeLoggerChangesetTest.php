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

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;

/**
 * A changeset groups the record changes made by one submission so the change log can
 * show that they went together and why. The row is written lazily, so a submission
 * that produced no actual change leaves no empty group behind.
 */
#[CoversClass(RecordChangeLogger::class)]
class RecordChangeLoggerChangesetTest extends TestCase
{
    private PDO $db;
    private RecordChangeLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE log_changesets (
            id integer PRIMARY KEY, zone_id integer, user_id integer, username VARCHAR(64) NOT NULL,
            comment TEXT, client_ip VARCHAR(64), created_at timestamp DEFAULT current_timestamp NOT NULL)");
        $this->db->exec("CREATE TABLE log_record_changes (
            id integer PRIMARY KEY, zone_id integer, changeset_id integer, record_id TEXT,
            action VARCHAR(32) NOT NULL, user_id integer, username VARCHAR(64) NOT NULL,
            before_state TEXT, after_state TEXT, client_ip VARCHAR(64),
            created_at timestamp DEFAULT current_timestamp NOT NULL)");

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')
            ->willReturnCallback(function ($group, $key, $default = null) {
                if ($group === 'logging' && $key === 'database_enabled') {
                    return true;
                }
                return $default;
            });

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        $userContext->method('getLoggedInUsername')->willReturn('alice');

        RecordChangeLogger::resetChangesetScope();
        $this->logger = new RecordChangeLogger($this->db, $userContext, $config);
    }

    protected function tearDown(): void
    {
        RecordChangeLogger::resetChangesetScope();
        parent::tearDown();
    }

    private function record(int $id, string $name, string $content): array
    {
        return ['id' => $id, 'name' => $name, 'type' => 'A', 'content' => $content, 'ttl' => 3600, 'prio' => 0];
    }

    private function changesetIds(): array
    {
        return $this->db->query("SELECT changeset_id FROM log_record_changes ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function changesetCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM log_changesets")->fetchColumn();
    }

    public function testChangesOutsideAScopeHaveNoChangeset(): void
    {
        $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);

        $this->assertSame(0, $this->changesetCount());
        $this->assertSame([null], $this->changesetIds());
    }

    public function testChangesInOneScopeShareASingleChangeset(): void
    {
        $this->logger->beginChangeset(10, 'move web tier to the new range');
        $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
        $this->logger->logRecordCreate($this->record(2, 'api.example.com', '192.0.2.2'), 10);
        $this->logger->logRecordDelete($this->record(3, 'old.example.com', '198.51.100.9'), 10);
        $this->logger->endChangeset();

        $this->assertSame(1, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertCount(3, $ids);
        $this->assertSame([$ids[0], $ids[0], $ids[0]], $ids);
        $this->assertNotNull($ids[0]);
    }

    public function testCommentIsStoredOnTheChangeset(): void
    {
        $this->logger->beginChangeset(10, '  move web tier  ');
        $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
        $this->logger->endChangeset();

        $row = $this->db->query("SELECT zone_id, user_id, username, comment FROM log_changesets")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('move web tier', $row['comment']);
        $this->assertSame(10, (int) $row['zone_id']);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame('alice', $row['username']);
    }

    public function testEmptyCommentIsStoredAsNull(): void
    {
        $this->logger->beginChangeset(10, '   ');
        $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
        $this->logger->endChangeset();

        $this->assertNull($this->db->query("SELECT comment FROM log_changesets")->fetchColumn());
    }

    public function testScopeThatLogsNothingLeavesNoChangeset(): void
    {
        $this->logger->beginChangeset(10, 'nothing actually changed');
        // logRecordEdit short-circuits when before and after are identical.
        $this->logger->logRecordEdit($this->record(1, 'www.example.com', '192.0.2.1'), $this->record(1, 'www.example.com', '192.0.2.1'), 10);
        $this->logger->endChangeset();

        $this->assertSame(0, $this->changesetCount());
    }

    public function testTwoScopesProduceTwoChangesets(): void
    {
        $this->logger->beginChangeset(10, 'first');
        $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        $this->logger->endChangeset();

        $this->logger->beginChangeset(10, 'second');
        $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
        $this->logger->endChangeset();

        $this->assertSame(2, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function testNestedScopesJoinTheOutermostChangeset(): void
    {
        $this->logger->beginChangeset(10, 'outer reason');
        $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);

        $this->logger->beginChangeset(99, 'inner reason that must not win');
        $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
        $this->logger->endChangeset();

        // Still inside the outer scope.
        $this->logger->logRecordCreate($this->record(3, 'c.example.com', '192.0.2.3'), 10);
        $this->logger->endChangeset();

        $this->assertSame(1, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertSame([$ids[0], $ids[0], $ids[0]], $ids);
        $this->assertSame('outer reason', $this->db->query("SELECT comment FROM log_changesets")->fetchColumn());
    }

    public function testScopeIsClosedAfterEndSoLaterChangesAreUngrouped(): void
    {
        $this->logger->beginChangeset(10, 'grouped');
        $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        $this->logger->endChangeset();

        $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);

        $ids = $this->changesetIds();
        $this->assertNotNull($ids[0]);
        $this->assertNull($ids[1]);
    }

    public function testScopeIsSharedAcrossLoggerInstances(): void
    {
        // RecordManager and the v2 controllers each build their own logger, so the
        // scope has to reach an instance the opener never saw.
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => ($group === 'logging' && $key === 'database_enabled') ? true : $default
        );
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        $userContext->method('getLoggedInUsername')->willReturn('alice');
        $other = new RecordChangeLogger($this->db, $userContext, $config);

        $this->logger->beginChangeset(10, 'one submission');
        $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        $other->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
        $this->logger->endChangeset();

        $this->assertSame(1, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertSame($ids[0], $ids[1]);
    }

    public function testUnbalancedEndCallIsHarmless(): void
    {
        $this->logger->endChangeset();
        $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);

        $this->assertSame(0, $this->changesetCount());
        $this->assertSame([null], $this->changesetIds());
    }

    public function testGetFilteredExposesTheChangesetComment(): void
    {
        $this->logger->beginChangeset(10, 'move web tier');
        $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
        $this->logger->endChangeset();
        $this->logger->logRecordCreate($this->record(2, 'lone.example.com', '192.0.2.9'), 10);

        $rows = $this->logger->getFiltered([], 50, 0);
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['after_state_decoded']['name']] = $row;
        }

        $this->assertSame('move web tier', $byName['www.example.com']['changeset_comment']);
        $this->assertNotNull($byName['www.example.com']['changeset_id']);
        // A change made outside a scope still comes back, just without a reason.
        $this->assertNull($byName['lone.example.com']['changeset_comment']);
        $this->assertNull($byName['lone.example.com']['changeset_id']);
    }

    public function testCommentFilterMatchesASubstring(): void
    {
        $this->logger->beginChangeset(10, 'migrating to the new range');
        $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        $this->logger->endChangeset();

        $this->logger->beginChangeset(10, 'routine cleanup');
        $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
        $this->logger->endChangeset();

        $matched = $this->logger->getFiltered(['comment' => 'new range'], 50, 0);
        $this->assertCount(1, $matched);
        $this->assertSame('a.example.com', $matched[0]['after_state_decoded']['name']);
        $this->assertSame(1, $this->logger->countFiltered(['comment' => 'new range']));

        // A blank filter must not narrow anything.
        $this->assertCount(2, $this->logger->getFiltered(['comment' => '   '], 50, 0));
        $this->assertSame(2, $this->logger->countFiltered([]));
    }

    public function testChangesetIsSkippedWhenDatabaseLoggingIsDisabled(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key, $default = null) => $default);
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        $userContext->method('getLoggedInUsername')->willReturn('alice');
        $logger = new RecordChangeLogger($this->db, $userContext, $config);

        $logger->beginChangeset(10, 'should not be written');
        $logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        $logger->endChangeset();

        $this->assertSame(0, $this->changesetCount());
        $this->assertSame([], $this->changesetIds());
    }
}
