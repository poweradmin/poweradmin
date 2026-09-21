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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZoneMetadataController;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneMetadataOutcome;
use Poweradmin\Domain\Service\ZoneMetadataResult;
use Poweradmin\Domain\Service\ZoneMetadataService;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The metadata endpoints keep their contract strings and statuses while the
 * rules themselves live in ZoneMetadataService.
 */
class ZoneMetadataControllerTest extends TestCase
{
    private const ZONE_ID = 1;
    private const USER_ID = 5;

    public function testListGroupsRowsByKindSorted(): void
    {
        $service = $this->createMock(ZoneMetadataService::class);
        $service->method('load')->with(self::ZONE_ID, 'example.com')->willReturn([
            ['kind' => 'SOA-EDIT-API', 'content' => 'EPOCH'],
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.11'],
        ]);

        $response = $this->call($service, 'listMetadata');
        $data = json_decode((string)$response->getContent(), true)['data']['metadata'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            ['kind' => 'ALLOW-AXFR-FROM', 'values' => ['192.0.2.10', '192.0.2.11']],
            ['kind' => 'SOA-EDIT-API', 'values' => ['EPOCH']],
        ], $data);
    }

    public function testAnUnknownZoneIs404(): void
    {
        $service = $this->createMock(ZoneMetadataService::class);
        $service->expects($this->never())->method('replaceKind');

        $response = $this->call($service, 'updateMetadataKind', 'ALLOW-AXFR-FROM', ['values' => ['192.0.2.10']], zoneName: null);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAMissingValuesFieldIs400BeforeTheServiceIsAsked(): void
    {
        $service = $this->createMock(ZoneMetadataService::class);
        $service->expects($this->never())->method('replaceKind');

        $response = $this->call($service, 'updateMetadataKind', 'ALLOW-AXFR-FROM', ['nope' => 1]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Missing required field: values', (string)$response->getContent());
    }

    public function testRefusalsKeepTheirContractWording(): void
    {
        $cases = [
            [new ZoneMetadataResult(ZoneMetadataOutcome::SINGLE_VALUE_ONLY, 'SOA-EDIT'), 400, 'Metadata kind SOA-EDIT accepts only a single value'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::INVALID_VALUE, 'SOA-EDIT-API', ['DEFAULT', 'INCREASE']), 422, 'Invalid value for SOA-EDIT-API. Allowed values: DEFAULT, INCREASE'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::COMPANION_REQUIRED, 'NSEC3NARROW', null, 'NSEC3PARAM'), 422, 'Metadata kind NSEC3NARROW only takes effect together with NSEC3PARAM'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::OPERATOR_ONLY, 'LUA-AXFR-SCRIPT'), 403, 'Metadata kind LUA-AXFR-SCRIPT can only be set by an administrator'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::SERVER_MANAGED, 'CATALOG-HASH'), 403, 'Metadata kind CATALOG-HASH is maintained by PowerDNS'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::NO_API_ROUTE, 'PRESIGNED'), 403, 'Metadata kind PRESIGNED is read-only'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::CUSTOM_PREFIX, 'MY-KIND'), 422, 'Custom metadata kind MY-KIND must start with X-'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::EMPTY_VALUES, 'X-NOTE'), 400, 'Values array must not be empty. Use DELETE to remove metadata.'],
            [new ZoneMetadataResult(ZoneMetadataOutcome::WRITE_FAILED, 'X-NOTE'), 500, 'Failed to update metadata'],
        ];

        foreach ($cases as [$result, $status, $message]) {
            $service = $this->createMock(ZoneMetadataService::class);
            $service->method('replaceKind')->willReturn($result);

            $response = $this->call($service, 'updateMetadataKind', 'X-NOTE', ['values' => ['1']]);

            $this->assertSame($status, $response->getStatusCode(), $message);
            $this->assertSame($message, json_decode((string)$response->getContent(), true)['message']);
        }
    }

    public function testASuccessfulPutPassesTheActingUserAndUppercasedKind(): void
    {
        $service = $this->createMock(ZoneMetadataService::class);
        $service->expects($this->once())->method('replaceKind')
            ->with(self::ZONE_ID, 'example.com', 'ALLOW-AXFR-FROM', ['192.0.2.10', '5'], self::USER_ID)
            ->willReturn(ZoneMetadataResult::ok());

        $response = $this->call($service, 'updateMetadataKind', 'allow-axfr-from', ['values' => ['192.0.2.10', 5]]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeleteWordsAWriteFailureForDelete(): void
    {
        $service = $this->createMock(ZoneMetadataService::class);
        $service->expects($this->once())->method('deleteKind')
            ->with(self::ZONE_ID, 'example.com', 'X-NOTE', self::USER_ID)
            ->willReturn(new ZoneMetadataResult(ZoneMetadataOutcome::WRITE_FAILED, 'X-NOTE'));

        $response = $this->call($service, 'deleteMetadataKind', 'X-NOTE');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Failed to delete metadata', json_decode((string)$response->getContent(), true)['message']);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(ZoneMetadataService $service, string $method, string $kind = '', ?array $body = null, ?string $zoneName = 'example.com'): JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canViewZoneMetadata')->willReturn(true);
        $permissions->method('canEditZoneMeta')->willReturn(true);
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainNameById')->willReturn($zoneName);

        $controller = (new ReflectionClass(ZoneMetadataController::class))->newInstanceWithoutConstructor();
        $this->inject($controller, 'metadataService', $service);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'domainRepository', $domainRepository);
        $this->inject($controller, 'pathParameters', ['id' => self::ZONE_ID, 'kind' => $kind]);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'request', new Request([], [], [], [], [], [], $body === null ? '' : json_encode($body)));

        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller);
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
