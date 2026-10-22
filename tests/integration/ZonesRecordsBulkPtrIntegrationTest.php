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

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\SessionActor;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

/**
 * create_ptr in the v2 bulk endpoint against the real services and the devcontainer
 * databases: the PTR lands in the reverse zone, that zone gets its own serial bump, and a
 * batch that fails afterwards takes the PTR back with it. A dry run leaves records, comments,
 * serials and the log tables as they were. Skipped when the engine is down.
 */
class ZonesRecordsBulkPtrIntegrationTest extends TestCase
{
    private const ADMIN_USER_ID = 1;
    private const SOA = 'ns1.example.com hostmaster.example.com 2020010101 28800 7200 604800 86400';

    private ?PDO $db = null;
    private string $pdns = '';
    private string $forwardZone = '';
    private int $forwardId = 0;
    private int $reverseId = 0;
    private string $engine = '';
    /** Per-process third octet, so sessions sharing the devcontainer database keep to their own reverse zone */
    private int $octet = 0;
    private bool $syncComments = false;
    private bool $dbLogging = false;

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    protected function tearDown(): void
    {
        RecordChangeLogger::resetChangesetScope();
        if ($this->db === null) {
            return;
        }
        foreach ([$this->forwardId, $this->reverseId] as $id) {
            if ($id > 0) {
                $this->purgeZone($id);
            }
        }
        $this->db = null;
    }

    #[DataProvider('engines')]
    public function testCreatePtrWritesThePtrAndBumpsTheReverseZoneSerialOnce(string $engine): void
    {
        $this->useEngine($engine);
        $before = $this->serialOf($this->reverseId);

        $response = $this->bulk([
            'comment' => 'ticket-1',
            'operations' => [
                ['action' => 'create', 'name' => 'www', 'type' => 'A', 'content' => "10.99.{$this->octet}.5", 'ttl' => 600, 'comment' => 'web server', 'create_ptr' => true],
                ['action' => 'create', 'name' => 'www2', 'type' => 'A', 'content' => "10.99.{$this->octet}.6", 'ttl' => 600, 'create_ptr' => true],
                ['action' => 'create', 'name' => 'txt', 'type' => 'TXT', 'content' => 'hello', 'create_ptr' => true],
            ],
        ]);
        $body = json_decode((string)$response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode(), (string)$response->getContent());
        $this->assertSame(3, $body['data']['created']);
        $this->assertSame([], $body['data']['warnings']);
        $this->assertSame(
            ['www.' . $this->forwardZone, 'www2.' . $this->forwardZone],
            $this->contents($this->reverseId, 'PTR')
        );
        $after = $this->serialOf($this->reverseId);
        $this->assertGreaterThan($before, $after, 'the reverse zone serial must move with its PTR records');
        $this->assertSame('web server', $this->commentOf($this->forwardId, 'www.' . $this->forwardZone, 'A'));
        $this->assertNull($this->commentOf($this->forwardId, 'www2.' . $this->forwardZone, 'A'));
    }

    #[DataProvider('engines')]
    public function testABatchFailingAfterAPtrOperationRollsThePtrBack(string $engine): void
    {
        $this->useEngine($engine);
        $before = $this->serialOf($this->reverseId);

        $response = $this->bulk(['operations' => [
            ['action' => 'create', 'name' => 'www', 'type' => 'A', 'content' => "10.99.{$this->octet}.5", 'create_ptr' => true],
            ['action' => 'update', 'id' => 2147483000, 'content' => "10.99.{$this->octet}.9"],
        ]]);

        $this->assertSame(404, $response->getStatusCode(), (string)$response->getContent());
        $this->assertSame([], $this->contents($this->reverseId, 'PTR'));
        $this->assertSame([], $this->contents($this->forwardId, 'A'));
        $this->assertSame($before, $this->serialOf($this->reverseId));
    }

    #[DataProvider('engines')]
    public function testAPtrWithoutAReverseZoneIsAWarningAndTheRecordStays(string $engine): void
    {
        $this->useEngine($engine);

        $response = $this->bulk(['operations' => [
            ['action' => 'create', 'name' => 'lonely', 'type' => 'A', 'content' => '10.250.250.250', 'create_ptr' => true],
        ]]);
        $body = json_decode((string)$response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode(), (string)$response->getContent());
        $this->assertSame(1, $body['data']['created']);
        $this->assertCount(1, $body['data']['warnings']);
        $this->assertStringStartsWith('Operation 0 (create): PTR record creation failed: ', $body['data']['warnings'][0]);
        $this->assertSame(['10.250.250.250'], $this->contents($this->forwardId, 'A'));
        $this->assertSame([], $this->contents($this->reverseId, 'PTR'));
    }

    #[DataProvider('engines')]
    public function testAnUpdateCommentReplacesAndClearsTheStoredOne(string $engine): void
    {
        $this->useEngine($engine);
        $this->bulk(['operations' => [
            ['action' => 'create', 'name' => 'www', 'type' => 'A', 'content' => "10.99.{$this->octet}.5", 'comment' => 'first'],
        ]]);
        $name = 'www.' . $this->forwardZone;
        $recordId = $this->recordId($this->forwardId, $name, 'A');

        $this->assertSame(200, $this->bulk(['operations' => [['action' => 'update', 'id' => $recordId, 'comment' => 'second']]])->getStatusCode());
        $this->assertSame('second', $this->commentOf($this->forwardId, $name, 'A'));

        $this->assertSame(200, $this->bulk(['operations' => [['action' => 'update', 'id' => $recordId, 'content' => "10.99.{$this->octet}.7"]]])->getStatusCode());
        $this->assertSame('second', $this->commentOf($this->forwardId, $name, 'A'), 'no comment in the operation leaves it alone');

        $this->assertSame(200, $this->bulk(['operations' => [['action' => 'update', 'id' => $recordId, 'comment' => '']]])->getStatusCode());
        $this->assertNull($this->commentOf($this->forwardId, $name, 'A'));
    }

    #[DataProvider('engines')]
    public function testUpdateCommentReplacesAndClearsTheSyncedPtrComment(string $engine): void
    {
        $this->syncComments = true;
        $this->useEngine($engine);
        $ptr = "5.{$this->octet}.99.10.in-addr.arpa";
        $this->bulk(['operations' => [
            ['action' => 'create', 'name' => 'www', 'type' => 'A', 'content' => "10.99.{$this->octet}.5", 'comment' => 'first', 'create_ptr' => true],
        ]]);
        $name = 'www.' . $this->forwardZone;
        $recordId = $this->recordId($this->forwardId, $name, 'A');
        $this->assertSame('first', $this->commentOf($this->reverseId, $ptr, 'PTR'), 'create syncs to the PTR');

        $this->assertSame(200, $this->bulk(['operations' => [['action' => 'update', 'id' => $recordId, 'comment' => 'second']]])->getStatusCode());
        $this->assertSame('second', $this->commentOf($this->forwardId, $name, 'A'));
        $this->assertSame('second', $this->commentOf($this->reverseId, $ptr, 'PTR'));

        $this->assertSame(200, $this->bulk(['operations' => [['action' => 'update', 'id' => $recordId, 'comment' => '']]])->getStatusCode());
        $this->assertNull($this->commentOf($this->forwardId, $name, 'A'));
        $this->assertNull($this->commentOf($this->reverseId, $ptr, 'PTR'), 'an empty comment clears the PTR too');
    }

    #[DataProvider('engines')]
    public function testACreateWhosePtrAlreadyExistsStillSyncsTheComment(string $engine): void
    {
        $this->syncComments = true;
        $this->useEngine($engine);
        $ptr = "5.{$this->octet}.99.10.in-addr.arpa";
        $this->db->prepare("INSERT INTO {$this->pdns}records (domain_id, name, type, content, ttl, prio) VALUES (?, ?, 'PTR', ?, 3600, 0)")
            ->execute([$this->reverseId, $ptr, 'www.' . $this->forwardZone]);

        $response = $this->bulk(['operations' => [
            ['action' => 'create', 'name' => 'www', 'type' => 'A', 'content' => "10.99.{$this->octet}.5", 'comment' => 'kept in step', 'create_ptr' => true],
        ]]);
        $body = json_decode((string)$response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode(), (string)$response->getContent());
        $this->assertCount(1, $body['data']['warnings']);
        $this->assertStringContainsString('PTR record creation failed', $body['data']['warnings'][0]);
        $this->assertSame('kept in step', $this->commentOf($this->reverseId, $ptr, 'PTR'));
    }

    #[DataProvider('engines')]
    public function testADryRunLeavesEveryTableAsItWas(string $engine): void
    {
        $this->dbLogging = true;
        $this->useEngine($engine);
        $this->bulk(['comment' => 'setup', 'operations' => [
            ['action' => 'create', 'name' => 'keep', 'type' => 'A', 'content' => "10.99.{$this->octet}.20", 'comment' => 'old'],
            ['action' => 'create', 'name' => 'gone', 'type' => 'A', 'content' => "10.99.{$this->octet}.21"],
        ]]);
        $keep = $this->recordId($this->forwardId, 'keep.' . $this->forwardZone, 'A');
        $gone = $this->recordId($this->forwardId, 'gone.' . $this->forwardZone, 'A');
        $operations = [
            ['action' => 'create', 'name' => 'www', 'type' => 'A', 'content' => "10.99.{$this->octet}.5", 'comment' => 'new', 'create_ptr' => true],
            ['action' => 'update', 'id' => $keep, 'content' => "10.99.{$this->octet}.22", 'comment' => 'changed'],
            ['action' => 'delete', 'id' => $gone],
        ];
        $before = $this->snapshot();
        $this->assertGreaterThan(0, $before['log_record_changes'], 'the change log must be on for this test to mean anything');

        $response = $this->bulk(['comment' => 'rehearsal', 'dry_run' => true, 'operations' => $operations]);
        $body = json_decode((string)$response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode(), (string)$response->getContent());
        $this->assertTrue($body['data']['dry_run']);
        $this->assertSame([1, 1, 1, 0], [$body['data']['created'], $body['data']['updated'], $body['data']['deleted'], $body['data']['failed']]);
        $this->assertSame([], $body['data']['warnings']);
        $this->assertSame($before, $this->snapshot());

        $real = json_decode((string)$this->bulk(['comment' => 'for real', 'operations' => $operations])->getContent(), true);
        $this->assertSame([1, 1, 1], [$real['data']['created'], $real['data']['updated'], $real['data']['deleted']]);
        $this->assertArrayNotHasKey('dry_run', $real['data']);
        $this->assertNotSame($before, $this->snapshot(), 'the same operations written for real must change something');
    }

    #[DataProvider('engines')]
    public function testADryRunReportsAnInvalidOperationAndWarnsAboutAMissingReverseZone(string $engine): void
    {
        $this->dbLogging = true;
        $this->useEngine($engine);
        $before = $this->snapshot();

        $warned = json_decode((string)$this->bulk(['dry_run' => true, 'operations' => [
            ['action' => 'create', 'name' => 'lonely', 'type' => 'A', 'content' => '10.250.250.250', 'create_ptr' => true],
        ]])->getContent(), true);
        $this->assertSame(1, $warned['data']['created']);
        $this->assertCount(1, $warned['data']['warnings']);
        $this->assertStringContainsString('PTR record creation failed', $warned['data']['warnings'][0]);

        $response = $this->bulk(['dry_run' => true, 'operations' => [
            ['action' => 'create', 'name' => 'ok', 'type' => 'A', 'content' => "10.99.{$this->octet}.8"],
            ['action' => 'create', 'name' => 'bad', 'type' => 'A', 'content' => 'not-an-address'],
        ]]);
        $body = json_decode((string)$response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode(), (string)$response->getContent());
        $this->assertSame(1, $body['data']['failed']);
        $this->assertCount(1, $body['data']['errors']);
        $this->assertStringStartsWith('Operation 1 (create): ', $body['data']['errors'][0]);
        $this->assertTrue($body['data']['dry_run']);
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * Everything a bulk call could touch in the two zones, comparable with assertSame.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $ids = $this->forwardId . ',' . $this->reverseId;
        $rows = fn(string $sql): array => $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $count = fn(string $table, string $column): int => (int)$this->db->query("SELECT COUNT(*) FROM $table WHERE $column IN ($ids)")->fetchColumn();

        return [
            'domains' => $rows("SELECT id, name, type FROM {$this->pdns}domains WHERE id IN ($ids) ORDER BY id"),
            'records' => $rows("SELECT id, domain_id, name, type, content, ttl, prio, disabled FROM {$this->pdns}records WHERE domain_id IN ($ids) ORDER BY id"),
            'comments' => $rows("SELECT id, domain_id, name, type, comment FROM {$this->pdns}comments WHERE domain_id IN ($ids) ORDER BY id"),
            'links' => (int)$this->db->query("SELECT COUNT(*) FROM record_comment_links WHERE comment_id IN (SELECT id FROM {$this->pdns}comments WHERE domain_id IN ($ids))")->fetchColumn(),
            'serials' => [$this->serialOf($this->forwardId), $this->serialOf($this->reverseId)],
            'log_record_changes' => $count('log_record_changes', 'zone_id'),
            'log_changesets' => $count('log_changesets', 'zone_id'),
            'log_zones' => $count('log_zones', 'zone_id'),
        ];
    }

    private function useEngine(string $engine): void
    {
        $this->engine = $engine;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        try {
            $this->db = $engine === 'mysql'
                ? new PDO('mysql:host=127.0.0.1;port=3306;dbname=poweradmin', 'pdns', 'poweradmin', $options)
                : new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options);
        } catch (PDOException $e) {
            $this->markTestSkipped("$engine is not reachable: " . $e->getMessage());
        }
        $this->pdns = $engine === 'mysql' ? 'pdns.' : '';

        $admin = $this->db->query('SELECT COUNT(*) FROM users WHERE id = ' . self::ADMIN_USER_ID)->fetchColumn();
        if ((int)$admin === 0) {
            $this->markTestSkipped('The devcontainer test data has no admin user.');
        }

        $this->octet = 1 + getmypid() % 254;
        $this->forwardZone = 'bulkptr-' . getmypid() . '.example.test';
        $this->forwardId = $this->createZone($this->forwardZone);
        $this->reverseId = $this->createZone($this->octet . '.99.10.in-addr.arpa');
    }

    private function createZone(string $name): int
    {
        $existing = $this->db->prepare("SELECT id FROM {$this->pdns}domains WHERE name = ?");
        $existing->execute([$name]);
        foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $stale) {
            $this->purgeZone((int)$stale);
        }

        $this->db->prepare("INSERT INTO {$this->pdns}domains (name, type) VALUES (?, 'NATIVE')")->execute([$name]);
        $id = (int)$this->db->query("SELECT id FROM {$this->pdns}domains WHERE name = " . $this->db->quote($name))->fetchColumn();
        $this->db->prepare("INSERT INTO {$this->pdns}records (domain_id, name, type, content, ttl, prio) VALUES (?, ?, 'SOA', ?, 86400, 0)")
            ->execute([$id, $name, self::SOA]);
        $this->db->prepare('INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (?, ?, 0)')->execute([$id, self::ADMIN_USER_ID]);

        return $id;
    }

    private function purgeZone(int $id): void
    {
        foreach (['log_record_changes', 'log_changesets', 'log_zones'] as $logTable) {
            $this->db->exec("DELETE FROM $logTable WHERE zone_id = $id");
        }
        $this->db->exec("DELETE FROM record_comment_links WHERE comment_id IN (SELECT id FROM {$this->pdns}comments WHERE domain_id = $id)");
        $this->db->exec("DELETE FROM {$this->pdns}comments WHERE domain_id = $id");
        $this->db->exec("DELETE FROM {$this->pdns}records WHERE domain_id = $id");
        $this->db->exec("DELETE FROM zones WHERE domain_id = $id");
        $this->db->exec("DELETE FROM {$this->pdns}domains WHERE id = $id");
    }

    private function serialOf(int $zoneId): int
    {
        $soa = $this->db->query("SELECT content FROM {$this->pdns}records WHERE domain_id = $zoneId AND type = 'SOA'")->fetchColumn();

        return (int)explode(' ', (string)$soa)[2];
    }

    /** @return list<string> */
    private function contents(int $zoneId, string $type): array
    {
        $rows = $this->db->query("SELECT content FROM {$this->pdns}records WHERE domain_id = $zoneId AND type = '$type' ORDER BY content")->fetchAll(PDO::FETCH_COLUMN);

        $rows = array_map('strval', $rows);
        sort($rows, SORT_STRING);

        return $rows;
    }

    private function recordId(int $zoneId, string $name, string $type): int
    {
        return (int)$this->db->query("SELECT id FROM {$this->pdns}records WHERE domain_id = $zoneId AND name = " . $this->db->quote($name) . " AND type = '$type'")->fetchColumn();
    }

    private function commentOf(int $zoneId, string $name, string $type): ?string
    {
        $id = $this->recordId($zoneId, $name, $type);
        $stmt = $this->db->prepare("SELECT c.comment FROM {$this->pdns}comments c JOIN record_comment_links l ON l.comment_id = c.id WHERE l.record_id = ?");
        $stmt->execute([(string)$id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string)$value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bulk(array $body): JsonResponse
    {
        RecordChangeLogger::resetChangesetScope();
        $defaults = require dirname(__DIR__, 2) . '/config/settings.defaults.php';
        $config = new FakeConfiguration(array_replace_recursive($defaults, [
            'database' => ['type' => $this->engine, 'pdns_db_name' => $this->engine === 'mysql' ? 'pdns' : ''],
            'misc' => ['record_comments_sync' => $this->syncComments],
            'logging' => ['database_enabled' => $this->dbLogging],
        ]));
        $session = new ArraySession(['userid' => self::ADMIN_USER_ID]);
        $factory = new ControllerServiceFactory($this->db, $config, new NullLogger(), new SessionActor($session), $session);

        $controller = (new ReflectionClass(ZonesRecordsBulkController::class))->newInstanceWithoutConstructor();
        $inject = function (string $property, mixed $value) use ($controller): void {
            $reflection = new ReflectionClass($controller);
            while (!$reflection->hasProperty($property)) {
                $reflection = $reflection->getParentClass();
            }
            $reflection->getProperty($property)->setValue($controller, $value);
        };
        $inject('request', Request::create('/api/v2/zones/' . $this->forwardId . '/records/bulk', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode($body)));
        $inject('logger', new NullLogger());
        $inject('config', $config);
        $inject('db', $this->db);
        $inject('serviceFactory', $factory);
        $inject('backendProvider', $factory->dnsBackendProvider());
        $inject('reverseTtlResolver', $factory->reverseTtlResolver());
        $inject('zoneRepository', $factory->zoneRepository());
        $inject('recordRepository', $factory->recordRepository());
        $inject('apiPermissionService', $factory->apiPermissionService());
        $inject('recordManager', $factory->recordManager());
        $inject('authenticatedUserId', self::ADMIN_USER_ID);
        $inject('apiKeyScope', ApiKeyScope::unrestricted());
        $inject('pathParameters', ['id' => $this->forwardId]);

        $response = (new ReflectionMethod($controller, 'bulkRecordOperations'))->invoke($controller);
        $this->assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }
}
