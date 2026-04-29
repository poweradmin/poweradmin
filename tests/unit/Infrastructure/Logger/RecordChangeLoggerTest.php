<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;

class RecordChangeLoggerTest extends TestCase
{
    private PDO $db;
    private RecordChangeLogger $logger;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE log_record_changes (
                id INTEGER PRIMARY KEY,
                zone_id INTEGER,
                record_id TEXT,
                action VARCHAR(32) NOT NULL,
                user_id INTEGER,
                username VARCHAR(64) NOT NULL,
                before_state TEXT,
                after_state TEXT,
                client_ip VARCHAR(64),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        $userContext->method('getLoggedInUsername')->willReturn('alice');

        $this->logger = new RecordChangeLogger($this->db, $userContext);
    }

    private function fetchAll(): array
    {
        return $this->db->query('SELECT * FROM log_record_changes ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testLogRecordCreateInsertsRowWithAfterState(): void
    {
        $this->logger->logRecordCreate(
            ['id' => 99, 'name' => 'www.example.com', 'type' => 'A', 'content' => '1.2.3.4', 'ttl' => 3600, 'prio' => 0],
            5
        );

        $rows = $this->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('record_create', $rows[0]['action']);
        $this->assertSame(5, (int) $rows[0]['zone_id']);
        $this->assertSame(99, (int) $rows[0]['record_id']);
        $this->assertSame('alice', $rows[0]['username']);
        $this->assertSame(7, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['before_state']);

        $after = json_decode((string) $rows[0]['after_state'], true);
        $this->assertSame('www.example.com', $after['name']);
        $this->assertSame('1.2.3.4', $after['content']);
    }

    public function testLogRecordDeleteInsertsRowWithBeforeState(): void
    {
        $this->logger->logRecordDelete(
            ['id' => 12, 'name' => 'old.example.com', 'type' => 'A', 'content' => '5.6.7.8', 'ttl' => 60, 'prio' => 0],
            5
        );

        $rows = $this->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('record_delete', $rows[0]['action']);
        $this->assertNull($rows[0]['after_state']);
        $this->assertNotNull($rows[0]['before_state']);
    }

    public function testLogRecordEditSkipsWhenBeforeAndAfterEqual(): void
    {
        $record = ['id' => 1, 'name' => 'a.example.com', 'type' => 'A', 'content' => '9.9.9.9', 'ttl' => 300, 'prio' => 0, 'disabled' => false];
        $this->logger->logRecordEdit($record, $record, 5);

        $rows = $this->fetchAll();
        $this->assertSame([], $rows, 'no-op edit must not insert a row');
    }

    public function testLogRecordEditTrimsTxtQuotesBeforeComparing(): void
    {
        $before = ['id' => 1, 'name' => 'a.example.com', 'type' => 'TXT', 'content' => 'hello', 'ttl' => 300, 'prio' => 0, 'disabled' => false];
        $after  = ['id' => 1, 'name' => 'a.example.com', 'type' => 'TXT', 'content' => '"hello"', 'ttl' => 300, 'prio' => 0, 'disabled' => false];

        $this->logger->logRecordEdit($before, $after, 5);
        $this->assertSame([], $this->fetchAll(), 'TXT records that differ only by quotes must not log');
    }

    public function testLogRecordEditWritesBothStatesWhenChanged(): void
    {
        $before = ['id' => 1, 'name' => 'a.example.com', 'type' => 'A', 'content' => '1.1.1.1', 'ttl' => 300, 'prio' => 0, 'disabled' => false];
        $after  = ['id' => 1, 'name' => 'a.example.com', 'type' => 'A', 'content' => '2.2.2.2', 'ttl' => 600, 'prio' => 0, 'disabled' => false];

        $this->logger->logRecordEdit($before, $after, 5);
        $rows = $this->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('record_edit', $rows[0]['action']);
        $this->assertNotNull($rows[0]['before_state']);
        $this->assertNotNull($rows[0]['after_state']);

        $beforeDecoded = json_decode((string) $rows[0]['before_state'], true);
        $afterDecoded = json_decode((string) $rows[0]['after_state'], true);
        $this->assertSame('1.1.1.1', $beforeDecoded['content']);
        $this->assertSame('2.2.2.2', $afterDecoded['content']);
        $this->assertSame(300, $beforeDecoded['ttl']);
        $this->assertSame(600, $afterDecoded['ttl']);
    }

    public function testLogZoneCreateAndZoneDeleteAndMetadataEdit(): void
    {
        $this->logger->logZoneCreate(['id' => 5, 'name' => 'example.com', 'type' => 'NATIVE', 'owner' => 7]);
        $this->logger->logZoneDelete(['id' => 5, 'name' => 'example.com', 'type' => 'NATIVE'], 14);
        $this->logger->logZoneMetadataEdit(
            ['id' => 5, 'name' => 'example.com', 'type' => 'MASTER', 'master' => null],
            ['id' => 5, 'name' => 'example.com', 'type' => 'SLAVE', 'master' => '10.0.0.1']
        );

        $rows = $this->fetchAll();
        $this->assertCount(3, $rows);
        $this->assertSame('zone_create', $rows[0]['action']);
        $this->assertSame('zone_delete', $rows[1]['action']);
        $this->assertSame('zone_metadata_edit', $rows[2]['action']);

        $deleted = json_decode((string) $rows[1]['before_state'], true);
        $this->assertSame(14, $deleted['record_count']);

        $metaBefore = json_decode((string) $rows[2]['before_state'], true);
        $metaAfter = json_decode((string) $rows[2]['after_state'], true);
        $this->assertSame('MASTER', $metaBefore['type']);
        $this->assertSame('SLAVE', $metaAfter['type']);
        $this->assertSame('10.0.0.1', $metaAfter['master']);
    }

    public function testZoneMetadataEditSkipsWhenUnchanged(): void
    {
        $zone = ['id' => 5, 'name' => 'example.com', 'type' => 'NATIVE', 'master' => null];
        $this->logger->logZoneMetadataEdit($zone, $zone);
        $this->assertSame([], $this->fetchAll());
    }

    public function testGetFilteredAttachesDecodedStatesAndChangedFields(): void
    {
        $this->logger->logRecordEdit(
            ['id' => 1, 'name' => 'a.example.com', 'type' => 'A', 'content' => '1.1.1.1', 'ttl' => 300, 'prio' => 0, 'disabled' => false],
            ['id' => 1, 'name' => 'a.example.com', 'type' => 'A', 'content' => '2.2.2.2', 'ttl' => 300, 'prio' => 0, 'disabled' => false],
            5
        );

        $rows = $this->logger->getFiltered([], 10, 0);
        $this->assertCount(1, $rows);
        $this->assertSame(['content'], $rows[0]['changed_fields']);
        $this->assertSame('1.1.1.1', $rows[0]['before_state_decoded']['content']);
        $this->assertSame('2.2.2.2', $rows[0]['after_state_decoded']['content']);
    }

    public function testCountFilteredHonoursActionFilter(): void
    {
        $this->logger->logRecordCreate(['name' => 'a', 'type' => 'A', 'content' => '1.1.1.1', 'ttl' => 60, 'prio' => 0], 1);
        $this->logger->logRecordCreate(['name' => 'b', 'type' => 'A', 'content' => '2.2.2.2', 'ttl' => 60, 'prio' => 0], 1);
        $this->logger->logRecordDelete(['name' => 'c', 'type' => 'A', 'content' => '3.3.3.3', 'ttl' => 60, 'prio' => 0], 1);

        $this->assertSame(3, $this->logger->countFiltered([]));
        $this->assertSame(2, $this->logger->countFiltered(['action' => 'record_create']));
        $this->assertSame(1, $this->logger->countFiltered(['action' => 'record_delete']));
    }

    public function testLongTxtContentTruncatesButKeepsValidJson(): void
    {
        $longContent = str_repeat('a', 10000);
        $this->logger->logRecordCreate(
            ['id' => 1, 'name' => 'big.example.com', 'type' => 'TXT', 'content' => $longContent, 'ttl' => 300, 'prio' => 0],
            5
        );

        $rows = $this->fetchAll();
        $this->assertCount(1, $rows);

        $decoded = json_decode((string) $rows[0]['after_state'], true);
        $this->assertIsArray($decoded, 'after_state must remain valid JSON');
        $this->assertSame(4096, strlen($decoded['content']));
        $this->assertTrue($decoded['_content_truncated']);
        $this->assertSame('big.example.com', $decoded['name']);
        $this->assertSame('TXT', $decoded['type']);
    }

    public function testLongCommentTruncatesIndependentlyOfContent(): void
    {
        $this->logger->logRecordCreate(
            [
                'id' => 1,
                'name' => 'a.example.com',
                'type' => 'A',
                'content' => '1.2.3.4',
                'ttl' => 60,
                'prio' => 0,
                'comment' => str_repeat('z', 8000),
            ],
            5
        );

        $decoded = json_decode((string) $this->fetchAll()[0]['after_state'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('1.2.3.4', $decoded['content']);
        $this->assertArrayNotHasKey('_content_truncated', $decoded);
        $this->assertSame(4096, strlen($decoded['comment']));
        $this->assertTrue($decoded['_comment_truncated']);
    }

    public function testShortContentIsNotTruncatedAndCarriesNoMarker(): void
    {
        $this->logger->logRecordCreate(
            ['id' => 1, 'name' => 'a.example.com', 'type' => 'TXT', 'content' => 'v=spf1 include:_spf.example.com ~all', 'ttl' => 300, 'prio' => 0],
            5
        );

        $decoded = json_decode((string) $this->fetchAll()[0]['after_state'], true);
        $this->assertSame('v=spf1 include:_spf.example.com ~all', $decoded['content']);
        $this->assertArrayNotHasKey('_content_truncated', $decoded);
    }

    public function testGetFilteredDecodesTruncatedSnapshotsCleanly(): void
    {
        $longBefore = str_repeat('b', 9000);
        $longAfter = str_repeat('c', 9000);
        $this->logger->logRecordEdit(
            ['id' => 1, 'name' => 'big.example.com', 'type' => 'TXT', 'content' => $longBefore, 'ttl' => 300, 'prio' => 0, 'disabled' => false],
            ['id' => 1, 'name' => 'big.example.com', 'type' => 'TXT', 'content' => $longAfter, 'ttl' => 300, 'prio' => 0, 'disabled' => false],
            5
        );

        $rows = $this->logger->getFiltered([], 10, 0);
        $this->assertCount(1, $rows);
        $this->assertIsArray($rows[0]['before_state_decoded'], 'before snapshot must decode');
        $this->assertIsArray($rows[0]['after_state_decoded'], 'after snapshot must decode');
        $this->assertSame(['content'], $rows[0]['changed_fields']);
        $this->assertTrue($rows[0]['before_state_decoded']['_content_truncated']);
        $this->assertTrue($rows[0]['after_state_decoded']['_content_truncated']);
    }
}
