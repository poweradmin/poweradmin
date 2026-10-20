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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Controller\DeleteRecordsController;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionClass;
use ReflectionProperty;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A bulk delete refuses an id that matches no record the way it refuses a foreign one,
 * so the messages never tell which ids exist, and does not trip over a row the same
 * batch already removed.
 *
 * UserManager::verifyPermission() caches the permission set in a static, so every
 * test runs in its own process.
 */
class DeleteRecordsControllerMissingRowsTest extends SqliteIntegrationTestCase
{
    private const ZONE_ID = 12;
    private const REFUSAL = 'You do not have the permission to delete this record.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0, ordername TEXT, auth INTEGER DEFAULT 1)");
        $this->db->exec("CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");
        foreach ([1, 3] as $id) {
            $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES ($id, " . self::ZONE_ID . ", 'host$id.example.com', 'A', '192.0.2.$id', 3600, 0)");
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    #[RunInSeparateProcess]
    public function testAMissingIdGetsTheRefusalAForeignRecordGets(): void
    {
        $this->deleteRecords([1, 99, 99]);

        $this->assertSame([3], $this->remainingRecordIds());
        $this->assertSame([self::REFUSAL], $this->systemErrors());
    }

    #[RunInSeparateProcess]
    public function testARowTheBatchAlreadyRemovedAndARepeatedIdAreSkippedSilently(): void
    {
        // Deleting 1 also takes 3, like a PTR companion delete would
        $this->deleteRecords([1, 3, 1], 3);

        $this->assertSame([], $this->remainingRecordIds());
        $this->assertSame([], $this->systemErrors());
    }

    /**
     * @param array<int|string> $ids
     */
    private function deleteRecords(array $ids, ?int $companionOfFirst = null): void
    {
        $_POST = ['delete_ptr' => '1'];

        $controller = new class ([], $this->db, $this->primeConfig()) extends DeleteRecordsController {
            use ControllerTestStubs;

            public function redirect(string $url, array $args = []): void
            {
            }

            public function setMessage(string $script, string $type, string $content): void
            {
            }
        };

        $reverse = $this->createMock(ReverseRecordCreator::class);
        $reverse->method('deleteReverseRecord')->willReturnCallback(function () use ($companionOfFirst): bool {
            if ($companionOfFirst !== null) {
                $this->db->exec("DELETE FROM records WHERE id = $companionOfFirst");
            }
            return true;
        });
        $values = [
            'auditLogger' => $this->createMock(LegacyLogger::class),
            'recordCommentService' => $this->createMock(RecordCommentService::class),
            'reverseRecordCreator' => $reverse,
            'userContextService' => new UserContextService(),
            'ipAddressRetriever' => new IpAddressRetriever([]),
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(DeleteRecordsController::class, $name))->setValue($controller, $value);
        }

        $controller->deleteRecords($ids);
    }

    /**
     * @return int[]
     */
    private function remainingRecordIds(): array
    {
        return array_map('intval', $this->db->query('SELECT id FROM records ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return string[]
     */
    private function systemErrors(): array
    {
        return array_column($_SESSION['messages']['system'] ?? [], 'content');
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $reflection->getProperty('settings')->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600],
            'interface' => ['add_reverse_record' => true],
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        return $config;
    }
}
