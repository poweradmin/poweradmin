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
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesChangeRequestsController;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsController;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\ZoneChangeRequestService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filing through /zones/{id}/change-requests, and the direct write endpoints
 * turning a request-mode caller away with the change request pointer.
 */
class ZonesChangeRequestsControllerTest extends TestCase
{
    private const USER_ID = 5;
    private const ZONE_ID = 100;

    private ZoneChangeRequestService&MockObject $service;
    private ZoneChangeRequestRepositoryInterface&MockObject $requests;
    private ApiPermissionService&MockObject $permissions;

    protected function setUp(): void
    {
        $this->service = $this->createMock(ZoneChangeRequestService::class);
        $this->requests = $this->createMock(ZoneChangeRequestRepositoryInterface::class);
        $this->permissions = $this->createMock(ApiPermissionService::class);
    }

    public function testFilingAnAddNormalisesTheRecordAndReturnsTheStoredRequest(): void
    {
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_REQUEST);
        $this->permissions->method('canRequestZoneRecord')->with(self::USER_ID, self::ZONE_ID, 'A', 'www.example.com', 'example.com')->willReturn(true);
        $this->service->expects($this->once())->method('fileRecordAdd')
            ->with(
                self::ZONE_ID,
                'example.com',
                ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => ''],
                self::USER_ID,
                'requester',
                'New web server'
            )
            ->willReturn(ZoneChangeRequestResult::ok(7, 'Change request filed for review.'));
        $this->requests->method('find')->with(7)->willReturn($this->storedRequest(7));

        $response = $this->file([
            'comment' => 'New web server',
            'actions' => [['op' => 'add', 'record' => ['name' => 'www', 'type' => 'a', 'content' => '192.0.2.1', 'ttl' => 3600]]],
        ]);
        $body = json_decode((string)$response->getContent(), true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(7, $body['data']['change_request']['id']);
        $this->assertSame([7], array_column($body['data']['change_requests'], 'id'));
        $this->assertSame('pending', $body['data']['change_request']['status']);
    }

    public function testAZoneDeleteMustBeTheOnlyAction(): void
    {
        $this->service->expects($this->never())->method('fileZoneDelete');

        $response = $this->file(['actions' => [['op' => 'zone_delete'], ['op' => 'delete', 'record_id' => 3]]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Only one action per change request is supported', json_decode((string)$response->getContent(), true)['message']);
    }

    public function testFilingIs404WhileApprovalIsOff(): void
    {
        $this->service->expects($this->never())->method('fileRecordAdd');

        $response = $this->file(['actions' => [['op' => 'add', 'record' => ['name' => 'www', 'type' => 'A', 'content' => '192.0.2.1']]]], enabled: false);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Change approval is not enabled', json_decode((string)$response->getContent(), true)['message']);
    }

    public function testADirectRecordWriteIsRefusedForARequestModeCaller(): void
    {
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->with(self::USER_ID, self::ZONE_ID)->willReturn(ChangeApprovalPolicy::MODE_REQUEST);
        $this->permissions->expects($this->never())->method('canEditZoneRecord');

        $controller = (new ReflectionClass(ZonesRecordsController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'zoneRepository', $this->zoneRepository());
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'config', $this->config(true));
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'request', new Request([], [], [], [], [], [], json_encode(['name' => 'www', 'type' => 'A', 'content' => '192.0.2.1'])));

        $response = (new ReflectionClass($controller))->getMethod('createRecord')->invoke($controller);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Changes to this zone require approval; create a change request instead',
            json_decode((string)$response->getContent(), true)['message']
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function file(array $body, bool $enabled = true): JsonResponse
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('requester');
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);
        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('resolveTtlForType')->willReturn(86400);

        $controller = (new ReflectionClass(ZonesChangeRequestsController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'zoneRepository', $this->zoneRepository());
        $this->inject($controller, 'recordRepository', $this->createMock(RecordRepositoryInterface::class));
        $this->inject($controller, 'recordComments', $this->createMock(RecordCommentRepositoryInterface::class));
        $this->inject($controller, 'requests', $this->requests);
        $this->inject($controller, 'changeRequests', $this->service);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'reverseTtlResolver', $ttl);
        $this->inject($controller, 'config', $this->config($enabled));
        $this->inject($controller, 'db', $db);
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID]);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'request', new Request([], [], [], [], [], [], json_encode($body)));

        return (new ReflectionClass($controller))->getMethod('fileChangeRequest')->invoke($controller);
    }

    private function zoneRepository(): ZoneReadRepositoryInterface&MockObject
    {
        $zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $zones->method('getZoneById')->with(self::ZONE_ID)->willReturn(['id' => self::ZONE_ID, 'name' => 'example.com', 'type' => 'MASTER']);

        return $zones;
    }

    private function config(bool $enabled): ConfigurationManager&MockObject
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $group === 'approval' && $key === 'enabled' ? $enabled : $default
        );

        return $config;
    }

    private function storedRequest(int $id): ZoneChangeRequest
    {
        return new ZoneChangeRequest(
            $id,
            self::ZONE_ID,
            'example.com',
            ZoneChangeRequest::KIND_RECORDS,
            ZoneChangeRequest::STATUS_PENDING,
            self::USER_ID,
            'requester',
            'New web server',
            null,
            [['op' => 'add', 'after' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => '']]],
            null,
            null,
            null,
            null,
            '2026-09-20 10:00:00',
            null,
            null,
            null
        );
    }

    private function inject(object $controller, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($controller);
        while (!$reflection->hasProperty($property)) {
            $reflection = $reflection->getParentClass();
        }
        $reflection->getProperty($property)->setValue($controller, $value);
    }
}
