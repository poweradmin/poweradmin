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
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use TestHelpers\FakeConfiguration;
use TestHelpers\StubActor;

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

        $config = new FakeConfiguration(['logging' => ['database_enabled' => true]]);

        $userContext = new StubActor(7, 'alice');

        RecordChangeLogger::resetChangesetScope();
        $this->logger = new RecordChangeLogger($this->db, $config, $userContext);
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
        $this->logger->withChangeset(10, 'move web tier to the new range', function () {
            $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
            $this->logger->logRecordCreate($this->record(2, 'api.example.com', '192.0.2.2'), 10);
            $this->logger->logRecordDelete($this->record(3, 'old.example.com', '198.51.100.9'), 10);
        });

        $this->assertSame(1, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertCount(3, $ids);
        $this->assertSame([$ids[0], $ids[0], $ids[0]], $ids);
        $this->assertNotNull($ids[0]);
    }

    public function testCommentIsStoredOnTheChangeset(): void
    {
        $this->logger->withChangeset(10, '  move web tier  ', function () {
            $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
        });

        $row = $this->db->query("SELECT zone_id, user_id, username, comment FROM log_changesets")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('move web tier', $row['comment']);
        $this->assertSame(10, (int) $row['zone_id']);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame('alice', $row['username']);
    }

    public function testEmptyCommentIsStoredAsNull(): void
    {
        $this->logger->withChangeset(10, '   ', function () {
            $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
        });

        $this->assertNull($this->db->query("SELECT comment FROM log_changesets")->fetchColumn());
    }

    public function testScopeThatLogsNothingLeavesNoChangeset(): void
    {
        $this->logger->withChangeset(10, 'nothing actually changed', function () {
            // logRecordEdit short-circuits when before and after are identical.
            $this->logger->logRecordEdit($this->record(1, 'www.example.com', '192.0.2.1'), $this->record(1, 'www.example.com', '192.0.2.1'), 10);
        });

        $this->assertSame(0, $this->changesetCount());
    }

    public function testTwoScopesProduceTwoChangesets(): void
    {
        $this->logger->withChangeset(10, 'first', function () {
            $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        });

        $this->logger->withChangeset(10, 'second', function () {
            $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
        });

        $this->assertSame(2, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertNotSame($ids[0], $ids[1]);
    }

    public function testNestedScopesJoinTheOutermostChangeset(): void
    {
        $this->logger->withChangeset(10, 'outer reason', function () {
            $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);

            $this->logger->withChangeset(99, 'inner reason that must not win', function () {
                $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
            });

            // Still inside the outer scope.
            $this->logger->logRecordCreate($this->record(3, 'c.example.com', '192.0.2.3'), 10);
        });

        $this->assertSame(1, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertSame([$ids[0], $ids[0], $ids[0]], $ids);
        $this->assertSame('outer reason', $this->db->query("SELECT comment FROM log_changesets")->fetchColumn());
    }

    public function testScopeIsClosedAfterEndSoLaterChangesAreUngrouped(): void
    {
        $this->logger->withChangeset(10, 'grouped', function () {
            $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        });

        $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);

        $ids = $this->changesetIds();
        $this->assertNotNull($ids[0]);
        $this->assertNull($ids[1]);
    }

    public function testScopeIsSharedAcrossLoggerInstances(): void
    {
        // RecordManager and the v2 controllers each build their own logger, so the
        // scope has to reach an instance the opener never saw.
        $config = new FakeConfiguration(['logging' => ['database_enabled' => true]]);
        $userContext = new StubActor(7, 'alice');
        $other = new RecordChangeLogger($this->db, $config, $userContext);

        $this->logger->withChangeset(10, 'one submission', function () use ($other) {
            $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
            $other->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
        });

        $this->assertSame(1, $this->changesetCount());
        $ids = $this->changesetIds();
        $this->assertSame($ids[0], $ids[1]);
    }

    public function testGetFilteredExposesTheChangesetComment(): void
    {
        $this->logger->withChangeset(10, 'move web tier', function () {
            $this->logger->logRecordCreate($this->record(1, 'www.example.com', '192.0.2.1'), 10);
        });
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
        $this->logger->withChangeset(10, 'migrating to the new range', function () {
            $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        });

        $this->logger->withChangeset(10, 'routine cleanup', function () {
            $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);
        });

        $matched = $this->logger->getFiltered(['comment' => 'new range'], 50, 0);
        $this->assertCount(1, $matched);
        $this->assertSame('a.example.com', $matched[0]['after_state_decoded']['name']);
        $this->assertSame(1, $this->logger->countFiltered(['comment' => 'new range']));

        // A blank filter must not narrow anything.
        $this->assertCount(2, $this->logger->getFiltered(['comment' => '   '], 50, 0));
        $this->assertSame(2, $this->logger->countFiltered([]));
    }

    public function testCrossZoneScopeLeavesTheChangesetZoneNull(): void
    {
        // A bulk delete can span zones, so the group must not claim whichever zone
        // happened to be touched first. The per-change rows still carry the truth.
        $this->logger->withChangeset(null, 'clean up stale records', function () {
            $this->logger->logRecordDelete($this->record(1, 'a.example.com', '192.0.2.1'), 10);
            $this->logger->logRecordDelete($this->record(2, 'b.example.net', '192.0.2.2'), 20);
        });

        $this->assertNull($this->db->query("SELECT zone_id FROM log_changesets")->fetchColumn());
        $this->assertSame(
            ['10', '20'],
            array_map('strval', $this->db->query("SELECT zone_id FROM log_record_changes ORDER BY id")->fetchAll(PDO::FETCH_COLUMN))
        );
    }

    public function testWithChangesetClosesTheScopeWhenWorkThrows(): void
    {
        try {
            $this->logger->withChangeset(10, 'will blow up', function () {
                $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected the exception to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        // The scope must not stay open, or the next unrelated change joins it.
        $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);

        $ids = $this->changesetIds();
        $this->assertNotNull($ids[0]);
        $this->assertNull($ids[1]);
    }

    public function testAThrowInANestedScopeLeavesTheOuterOneUsableAndThenClosed(): void
    {
        $this->logger->withChangeset(10, 'outer', function () {
            try {
                $this->logger->withChangeset(99, 'inner', function () {
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
                // expected
            }
            $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        });
        $this->logger->logRecordCreate($this->record(2, 'b.example.com', '192.0.2.2'), 10);

        $ids = $this->changesetIds();
        $this->assertNotNull($ids[0]);
        $this->assertNull($ids[1]);
        $this->assertSame('outer', $this->db->query("SELECT comment FROM log_changesets")->fetchColumn());
    }

    public function testWithChangesetReturnsTheWorkResult(): void
    {
        $result = $this->logger->withChangeset(10, 'ok', fn() => 'done');
        $this->assertSame('done', $result);
    }

    public function testCommentIsCappedAtTheServerSideLimit(): void
    {
        $this->logger->withChangeset(10, str_repeat('x', 1500), function () {
            $this->logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        });

        $stored = (string) $this->db->query("SELECT comment FROM log_changesets")->fetchColumn();
        $this->assertSame(1000, mb_strlen($stored));
    }

    public function testChangesetIsSkippedWhenDatabaseLoggingIsDisabled(): void
    {
        $config = new FakeConfiguration();
        $userContext = new StubActor(7, 'alice');
        $logger = new RecordChangeLogger($this->db, $config, $userContext);

        $this->logger->withChangeset(10, 'should not be written', function () use ($logger) {
            $logger->logRecordCreate($this->record(1, 'a.example.com', '192.0.2.1'), 10);
        });

        $this->assertSame(0, $this->changesetCount());
        $this->assertSame([], $this->changesetIds());
    }

    public function testCommentRequirementIsReadFromTheInjectedConfiguration(): void
    {
        $userContext = StubActor::nobody();
        $strict = new RecordChangeLogger($this->db, new FakeConfiguration(['logging' => [
            'database_enabled' => true,
            'require_change_comment' => true,
        ]]), $userContext);

        $this->assertTrue($strict->changeCommentRequired());
        $this->assertFalse($this->logger->changeCommentRequired());

        $this->assertSame('ran', $this->logger->withChangeset(10, null, fn() => 'ran'));

        $this->expectException(\InvalidArgumentException::class);
        $strict->withChangeset(10, '  ', fn() => null);
    }
}
