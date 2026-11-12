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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * A record update that sends no content keeps the stored content: control characters left by
 * older versions are collapsed.
 */
class StoredContentFallbackTest extends TestCase
{
    private PDO $db;
    /** @var array<int, array<string, mixed>> */
    private array $edits = [];

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT, account TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES (3, 'example.com', 'MASTER')");
        $_SERVER['REQUEST_METHOD'] = 'PUT';
    }

    #[RunInSeparateProcess]
    public function testBulkUpdateWithoutContentHealsALegacyCaaTab(): void
    {
        $this->bulkUpdate(['id' => 7], $this->record('CAA', "0 issue\t\"ca.example\""));

        $this->assertSame('0 issue "ca.example"', $this->edits[0]['content']);
    }

    #[RunInSeparateProcess]
    public function testPutWithoutContentHealsALegacyCaaTab(): void
    {
        $this->put($this->record('CAA', "0 issue\t\"ca.example\""));

        $this->assertSame('0 issue "ca.example"', $this->edits[0]['content']);
    }

    /** @return array<string, mixed> */
    private function record(string $type, string $content): array
    {
        return ['id' => 7, 'domain_id' => 3, 'name' => 'example.com', 'type' => $type, 'content' => $content, 'ttl' => 300, 'prio' => 0, 'disabled' => 0];
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $record */
    private function bulkUpdate(array $operation, array $record): void
    {
        $controller = $this->controller(ZonesRecordsBulkController::class, [], $record);
        $method = (new ReflectionClass($controller))->getMethod('performUpdateOperation');
        $method->invoke($controller, 3, $operation, 'MASTER', 'example.com');
    }

    /** @param array<string, mixed> $record */
    private function put(array $record): void
    {
        $controller = $this->controller(ZonesRecordsController::class, ['ttl' => 300], $record);
        $method = (new ReflectionClass($controller))->getMethod('updateRecord');
        $method->invoke($controller);
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $body
     * @param array<string, mixed> $record
     */
    private function controller(string $class, array $body, array $record): object
    {
        $config = ConfigurationManager::getInstance();
        $config->initialize();

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneById')->willReturn(['id' => 3, 'type' => 'MASTER', 'name' => 'example.com']);
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordById')->willReturn($record);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneContent')->willReturn(true);
        $permissions->method('canEditZoneRecord')->willReturn(true);
        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('editRecord')->willReturnCallback(function (array $data): bool {
            $this->edits[] = $data;
            return true;
        });
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(false);

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $request = Request::create('/api', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode($body));
        $values = [
            'request' => $request, 'pathParameters' => ['id' => '3', 'record_id' => '7'], 'authenticatedUserId' => 5,
            'apiKeyScope' => ApiKeyScope::unrestricted(), 'db' => $this->db, 'config' => $config,
            'zoneRepository' => $zones, 'recordRepository' => $records, 'permissionService' => $permissions,
            'recordManager' => $manager, 'backendProvider' => $backend, 'logger' => new NullLogger(),
        ];
        foreach ($values as $name => $value) {
            for ($c = new ReflectionClass($controller); $c !== false; $c = $c->getParentClass()) {
                if ($c->hasProperty($name)) {
                    $c->getProperty($name)->setValue($controller, $value);
                    break;
                }
            }
        }

        return $controller;
    }
}
