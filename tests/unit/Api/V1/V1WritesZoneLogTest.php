<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Api\V1;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V1\ZonesController;
use Poweradmin\Application\Controller\Api\V1\ZonesRecordsController;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * The v1 zone and record writes leave the same zone log entries as the v2 ones.
 */
class V1WritesZoneLogTest extends TestCase
{
    /** @var list<array{string, ?int}> */
    private array $logged = [];

    private function logger(): LegacyLogger
    {
        $logger = $this->createMock(LegacyLogger::class);
        $logger->method('logInfo')->willReturnCallback(function (string $message, ?int $zoneId = null): void {
            $this->logged[] = [$message, $zoneId];
        });

        return $logger;
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $db->exec("INSERT INTO users VALUES (7, 'apiuser')");
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)');
        $db->exec("INSERT INTO domains VALUES (3, 'a.example', 'MASTER')");
        $db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER, ordername TEXT, auth INTEGER)');
        $db->exec('CREATE TABLE log_zones (id INTEGER PRIMARY KEY, zone_id INTEGER, event TEXT, priority INTEGER)');

        return $db;
    }

    private function config(): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn($group, $key, $default = null) => [
            'database.type' => 'sqlite',
            'dns.hostmaster' => 'hostmaster.a.example',
            'dns.ttl' => 3600,
        ]["$group.$key"] ?? $default);

        return $config;
    }

    private function assertLogged(string $operation, int $zoneId): void
    {
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('user:apiuser', $this->logged[0][0]);
        $this->assertStringContainsString("operation:$operation", $this->logged[0][0]);
        $this->assertSame($zoneId, $this->logged[0][1]);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function build(string $class, array $properties, ?array $body = null): object
    {
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $base = new ReflectionClass($class);
        $baseProps = [
            'db' => $this->db(),
            'config' => $this->config(),
            'auditLogger' => $this->logger(),
            'ipAddressRetriever' => new IpAddressRetriever([]),
            'authenticatedUserId' => 7,
            'request' => new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body ?? [])),
        ];
        foreach (array_merge($baseProps, $properties) as $name => $value) {
            $reflection = $base;
            while (!$reflection->hasProperty($name)) {
                $reflection = $reflection->getParentClass();
            }
            $reflection->getProperty($name)->setValue($controller, $value);
        }

        return $controller;
    }

    private function call(object $controller, string $method): int
    {
        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller)->getStatusCode();
    }

    public function testCreateZoneIsLogged(): void
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canCreateZone')->willReturn(true);
        $service = $this->createMock(ZoneManagementService::class);
        $service->method('createZone')->willReturn(['success' => true, 'zone_id' => 11]);

        $controller = $this->build(ZonesController::class, [
            'permissionService' => $permissions,
            'zoneManagementService' => $service,
            'pathParameters' => [],
        ], ['name' => 'new.example', 'type' => 'NATIVE']);

        $this->assertSame(201, $this->call($controller, 'createZone'));
        $this->assertLogged('api_add_zone', 11);
    }

    public function testDeleteZoneIsLogged(): void
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canDeleteZone')->willReturn(true);
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneById')->willReturn(['id' => 3, 'name' => 'a.example']);
        $service = $this->createMock(ZoneManagementService::class);
        $service->method('deleteZone')->willReturn(['success' => true]);
        $provider = $this->createMock(DnsBackendProvider::class);

        $class = new class ($provider) extends ZonesController {
            public function __construct(private DnsBackendProvider $provider)
            {
            }

            protected function createDnsBackendProvider(): DnsBackendProvider
            {
                return $this->provider;
            }
        };

        $controller = $this->build($class::class, [
            'permissionService' => $permissions,
            'zoneRepository' => $zones,
            'zoneManagementService' => $service,
            'provider' => $provider,
            'pathParameters' => ['id' => 3],
        ]);

        $this->assertSame(204, $this->call($controller, 'deleteZone'));
        $this->assertLogged('api_delete_zone', 3);
    }

    public function testCreateRecordIsLogged(): void
    {
        $controller = $this->recordsController(
            ['name' => 'www', 'type' => 'A', 'content' => '192.0.2.9'],
            [],
        );

        $this->assertSame(201, $this->call($controller, 'createRecord'));
        $this->assertLogged('api_add_record', 3);
    }

    public function testCreateRecordStoresTheValidatorsCanonicalContent(): void
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('isApiBackend')->willReturn(false);
        $provider->expects($this->once())->method('createRecordAtomic')
            ->with(3, 'a.example', 'SOA', 'ns1.a.example hostmaster.a.example 1 7200 3600 1209600 3600', 3600, 0, 0)
            ->willReturn(99);

        $controller = $this->recordsController(
            ['name' => '@', 'type' => 'SOA', 'content' => 'ns1.a.example. hostmaster@a.example 1 7200 3600 1209600 3600'],
            [],
            $provider,
        );

        $this->assertSame(201, $this->call($controller, 'createRecord'));
        $this->assertStringContainsString('hostmaster.a.example', $this->logged[0][0]);
        $this->assertStringNotContainsString('hostmaster@', $this->logged[0][0]);
    }

    public function testUpdateRecordIsLogged(): void
    {
        $controller = $this->recordsController(
            ['name' => 'www.a.example', 'content' => '192.0.2.9'],
            [],
        );

        $this->assertSame(200, $this->call($controller, 'updateRecord'));
        $this->assertLogged('api_edit_record', 3);
    }

    public function testDeleteRecordIsLogged(): void
    {
        $controller = $this->recordsController([], []);

        $this->assertSame(204, $this->call($controller, 'deleteRecord'));
        $this->assertLogged('api_delete_record', 3);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $unused
     */
    private function recordsController(array $body, array $unused, ?DnsBackendProvider $provider = null): object
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneContent')->willReturn(true);
        $permissions->method('canEditZoneRecord')->willReturn(true);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneById')->willReturn(['id' => 3, 'name' => 'a.example', 'type' => 'MASTER']);
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordById')->willReturn([
            'id' => 5, 'domain_id' => 3, 'name' => 'www.a.example', 'type' => 'A', 'content' => '192.0.2.1',
            'ttl' => 3600, 'prio' => 0, 'disabled' => 0,
        ]);
        $records->method('hasSimilarRecords')->willReturn(true);
        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('editRecord')->willReturn(true);
        $manager->method('deleteRecord')->willReturn(true);

        if ($provider === null) {
            $provider = $this->createMock(DnsBackendProvider::class);
            $provider->method('isApiBackend')->willReturn(false);
            $provider->method('createRecordAtomic')->willReturn(99);
        }

        return $this->build(ZonesRecordsController::class, [
            'backendProvider' => $provider,
            'logger' => new NullLogger(),
            'permissionService' => $permissions,
            'zoneRepository' => $zones,
            'recordRepository' => $records,
            'recordManager' => $manager,
            'soaRecordManager' => $this->createMock(SOARecordManager::class),
            'recordCommentService' => $this->createMock(RecordCommentService::class),
            'pathParameters' => ['id' => 3, 'record_id' => 5],
        ], $body);
    }
}
