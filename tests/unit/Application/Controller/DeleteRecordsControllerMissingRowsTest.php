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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DeleteRecordsController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionProperty;
use RuntimeException;

/**
 * A bulk delete refuses an id that matches no record the way it refuses a foreign one,
 * so the messages never tell which ids exist, and does not trip over a row the same
 * batch already removed.
 */
class DeleteRecordsControllerMissingRowsTest extends TestCase
{
    private const ZONE_ID = 12;
    private const REFUSAL = 'You do not have the permission to delete this record.';

    private array $savedSession = [];
    private array $savedPost = [];

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $this->savedPost = $_POST;
        $_SESSION = [];
        $_POST = ['change_comment' => 'cleanup'];

        foreach ([1, 3] as $id) {
            $this->rows[$id] = ['id' => $id, 'domain_id' => self::ZONE_ID, 'name' => "host$id.example.com", 'type' => 'A', 'content' => "192.0.2.$id", 'ttl' => 3600, 'prio' => 0];
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        $_POST = $this->savedPost;
    }

    public function testAMissingIdGetsTheRefusalAForeignRecordGets(): void
    {
        $deleted = $this->deleteRecords([1, 99, 99]);

        $this->assertSame([1], $deleted);
        $this->assertSame([self::REFUSAL], $this->systemErrors());
    }

    public function testARowTheBatchAlreadyRemovedAndARepeatedIdAreSkippedSilently(): void
    {
        // Deleting 1 also takes 3, like a PTR companion delete would
        $deleted = $this->deleteRecords([1, 3, 1], [1 => [3]]);

        $this->assertSame([1], $deleted);
        $this->assertSame([], $this->systemErrors());
    }

    /**
     * @param array<int|string> $ids
     * @param array<int, int[]> $companions record id => other ids its delete removes
     * @return array<int|string> ids handed to RecordManager::deleteRecord()
     */
    private function deleteRecords(array $ids, array $companions = []): array
    {
        $controller = new class ([]) extends DeleteRecordsController {
            use ControllerTestStubs;

            public ?SOARecordManagerInterface $soa = null;

            public function redirect(string $url, array $args = []): void
            {
            }

            public function setMessage(string $script, string $type, string $content): void
            {
            }

            protected function createSOARecordManager(): SOARecordManagerInterface
            {
                return $this->soa ?? throw new RuntimeException('No SOA manager stub');
            }

            protected function rectifyZoneAfterWrite(string $zoneName): void
            {
            }
        };
        (new ReflectionProperty(BaseController::class, 'db'))->getValue($controller)
            ->exec('CREATE TABLE records_zone_templ (record_id INTEGER)');

        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordFromId')->willReturnCallback(fn(int|string $id): ?array => $this->rows[(int)$id] ?? null);
        $records->method('getZoneIdFromRecordId')->willReturnCallback(fn(int|string $id): int => isset($this->rows[(int)$id]) ? self::ZONE_ID : 0);
        $records->method('recidToDomid')->willReturn(self::ZONE_ID);
        $controller->records = $records;

        $deleted = [];
        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('deleteRecord')->willReturnCallback(function (int|string $id) use (&$deleted, $companions): bool {
            $deleted[] = $id;
            unset($this->rows[(int)$id]);
            foreach ($companions[(int)$id] ?? [] as $companion) {
                unset($this->rows[$companion]);
            }
            return true;
        });
        $controller->recordManager = $manager;
        $controller->domains = $this->createMock(DomainRepositoryInterface::class);
        $controller->soa = $this->createMock(SOARecordManagerInterface::class);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);
        $controller->setConfig($config);
        (new ReflectionProperty(BaseController::class, 'messageService'))->setValue($controller, new MessageService());

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        $values = [
            'request' => new Request(),
            'auditLogger' => $this->createMock(LegacyLogger::class),
            'recordCommentService' => $this->createMock(RecordCommentService::class),
            'reverseRecordCreator' => $this->createMock(ReverseRecordCreator::class),
            'userContextService' => $userContext,
            'ipAddressRetriever' => new IpAddressRetriever([]),
            'permissionService' => $this->createMock(PermissionService::class),
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(DeleteRecordsController::class, $name))->setValue($controller, $value);
        }

        $controller->deleteRecords($ids);

        return $deleted;
    }

    /**
     * @return string[]
     */
    private function systemErrors(): array
    {
        return array_column($_SESSION[SessionKeys::MESSAGES]['system'] ?? [], 'content');
    }
}
