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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ChangeRequestsController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * The change request endpoints: hidden while the feature is off, scoped to
 * what the caller may review or filed, and relaying the service's decision.
 */
class ChangeRequestsControllerTest extends TestCase
{
    private const USER_ID = 5;
    private const OWNED_ZONE = 100;

    private ZoneChangeRequestRepositoryInterface&MockObject $requests;
    private ZoneChangeRequestService&MockObject $service;
    private ApiPermissionService&MockObject $permissions;

    protected function setUp(): void
    {
        $this->requests = $this->createMock(ZoneChangeRequestRepositoryInterface::class);
        $this->service = $this->createMock(ZoneChangeRequestService::class);
        $this->permissions = $this->createMock(ApiPermissionService::class);
    }

    public function testEveryEndpointIs404WhileApprovalIsOff(): void
    {
        $this->requests->expects($this->never())->method('find');
        $this->service->expects($this->never())->method('approve');

        foreach (['listChangeRequests', 'getChangeRequest', 'approveChangeRequest', 'rejectChangeRequest', 'cancelChangeRequest'] as $method) {
            $response = $this->call($method, enabled: false, id: 12);

            $this->assertSame(404, $response->getStatusCode(), $method);
            $this->assertSame('Change approval is not enabled', $this->body($response)['message'], $method);
        }
    }

    public function testTheListAsksForTheReviewersZonesOrTheirOwnRequestsInOneQuery(): void
    {
        $this->permissions->method('getReviewableZoneIds')->with(self::USER_ID)->willReturn([self::OWNED_ZONE]);
        $reviewable = $this->request(1, self::OWNED_ZONE, requesterId: 9, createdAt: '2026-09-20 10:00:00');
        $own = $this->request(2, 300, requesterId: self::USER_ID, createdAt: '2026-09-20 11:00:00');
        $expected = ['status' => 'pending', 'zoneIds' => null, 'reviewableZoneIds' => [self::OWNED_ZONE], 'orRequesterId' => self::USER_ID];
        $this->requests->expects($this->once())->method('count')->with($expected)->willReturn(2);
        $this->requests->expects($this->once())->method('list')->with($expected, 0, 25)->willReturn([$own, $reviewable]);

        $response = $this->call('listChangeRequests', query: ['per_page' => 25]);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([2, 1], array_column($body['data']['change_requests'], 'id'));
        $this->assertSame(['id' => self::USER_ID, 'username' => 'requester'], $body['data']['change_requests'][0]['requester']);
        $this->assertNull($body['data']['change_requests'][0]['reviewer']);
        $this->assertSame(['current_page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1], $body['pagination']);
    }

    public function testAZoneFilterStaysWithinTheKeysZoneScope(): void
    {
        $this->permissions->method('getReviewableZoneIds')->willReturn(null);
        $this->requests->method('count')->willReturn(0);
        $this->requests->expects($this->once())->method('list')
            ->with(['status' => 'pending', 'zoneIds' => []], 0, $this->anything())
            ->willReturn([]);

        $response = $this->call('listChangeRequests', query: ['zone_id' => 300], scope: new ApiKeyScope([self::OWNED_ZONE], null, false));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->body($response)['data']['change_requests']);
    }

    public function testApprovingNeedsTheOperationsTheRequestWouldPerform(): void
    {
        $this->requests->method('find')->with(12)->willReturn($this->request(12, self::OWNED_ZONE));
        $this->permissions->method('canReviewChangeRequests')->willReturn(true);
        $this->service->expects($this->never())->method('approve');

        $response = $this->call('approveChangeRequest', id: 12, scope: new ApiKeyScope(null, [ApiKeyScope::OP_VIEW, ApiKeyScope::OP_UPDATE], false));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: this API key is not permitted to perform this operation', $this->body($response)['message']);
    }

    public function testApprovingWithoutTheReviewPermissionIs403(): void
    {
        $this->requests->method('find')->with(12)->willReturn($this->request(12, self::OWNED_ZONE));
        $this->permissions->method('canReviewChangeRequests')->with(self::USER_ID, self::OWNED_ZONE)->willReturn(false);
        $this->service->expects($this->never())->method('approve');

        $response = $this->call('approveChangeRequest', id: 12);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to review change requests for this zone', $this->body($response)['message']);
    }

    public function testApprovingRelaysTheServiceOutcomeWithTheRequestsNewState(): void
    {
        $pending = $this->request(12, self::OWNED_ZONE);
        $approved = $this->request(12, self::OWNED_ZONE, status: ZoneChangeRequest::STATUS_APPROVED);
        $this->requests->method('find')->with(12)->willReturnOnConsecutiveCalls($pending, $approved);
        $this->permissions->method('canReviewChangeRequests')->willReturn(true);
        $this->service->expects($this->once())->method('approve')
            ->with(12, self::USER_ID, 'reviewer', 'Looks good')
            ->willReturn(ZoneChangeRequestResult::ok(12, 'Change request approved and applied.'));

        $response = $this->call('approveChangeRequest', id: 12, body: ['comment' => 'Looks good']);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Change request approved and applied.', $body['message']);
        $this->assertSame('approved', $body['data']['change_request']['status']);
    }

    public function testAFailedApplyKeepsTheServiceStatusAndMessage(): void
    {
        $this->requests->method('find')->willReturn($this->request(12, self::OWNED_ZONE));
        $this->permissions->method('canReviewChangeRequests')->willReturn(true);
        $this->service->method('approve')
            ->willReturn(ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_APPLY_FAILED, 'Action 1 (add) failed: duplicate. Nothing was applied.', Refusal::CONFLICT, [], 12));

        $response = $this->call('approveChangeRequest', id: 12);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Action 1 (add) failed: duplicate. Nothing was applied.', $this->body($response)['message']);
    }

    public function testOnlyTheRequesterMayCancel(): void
    {
        $this->requests->method('find')->willReturn($this->request(12, self::OWNED_ZONE, requesterId: 9));
        $this->service->expects($this->never())->method('cancel');

        $response = $this->call('cancelChangeRequest', id: 12);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function request(int $id, int $zoneId, int $requesterId = self::USER_ID, string $status = ZoneChangeRequest::STATUS_PENDING, string $createdAt = '2026-09-20 10:00:00'): ZoneChangeRequest
    {
        return new ZoneChangeRequest(
            $id,
            $zoneId,
            'example.com',
            ZoneChangeRequest::KIND_RECORDS,
            $status,
            $requesterId,
            'requester',
            null,
            '2026092001',
            [['op' => 'add', 'after' => ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'comment' => '']]],
            null,
            null,
            null,
            null,
            $createdAt,
            null,
            null,
            null
        );
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, bool $enabled = true, ?int $id = null, ?array $body = null, array $query = [], ?ApiKeyScope $scope = null): JsonResponse
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $group === 'approval' && $key === 'enabled' ? $enabled : $default
        );
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getUserById')->willReturn(['id' => self::USER_ID, 'username' => 'reviewer']);
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('userRepository')->willReturn($users);

        $controller = (new ReflectionClass(ChangeRequestsController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'requests', $this->requests);
        $this->inject($controller, 'changeRequests', $this->service);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'config', $config);
        $this->inject($controller, 'serviceFactory', $factory);
        $this->inject($controller, 'pathParameters', $id === null ? [] : ['id' => $id]);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'request', new Request($query, [], [], [], [], [], $body === null ? '' : json_encode($body)));
        $this->inject($controller, 'apiKeyScope', $scope);

        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(JsonResponse $response): array
    {
        return json_decode((string)$response->getContent(), true);
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
