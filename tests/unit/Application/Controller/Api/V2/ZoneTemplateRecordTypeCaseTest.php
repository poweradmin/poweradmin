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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\ZoneTemplateRecordsController;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\Template\ZoneTemplateRecordValidationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use TestHelpers\FakeConfiguration;

/**
 * Template record writes accept a lowercase type, as they did before the
 * unknown-type check, and store it uppercase like the zone record endpoints.
 */
#[CoversClass(ZoneTemplateRecordsController::class)]
class ZoneTemplateRecordTypeCaseTest extends V2ControllerTestCase
{
    private const USER_ID = 1;
    private const TEMPLATE_ID = 5;
    private const RECORD_ID = 12;

    /** @var ZoneTemplateRepositoryInterface&MockObject */
    private ZoneTemplateRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->repository->method('isOwner')->willReturn(true);
        $this->repository->method('getZoneTemplateRecordById')->willReturn([
            'id' => self::RECORD_ID, 'zone_templ_id' => self::TEMPLATE_ID, 'name' => 'www.[ZONE]',
            'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0,
        ]);
    }

    public function testCreateStoresALowercaseTypeUppercase(): void
    {
        $this->repository->expects($this->once())->method('addRecord')
            ->with(self::TEMPLATE_ID, 'www.[ZONE]', 'A', '192.0.2.10', 3600, 0)
            ->willReturn(99);

        $response = $this->send('POST', 'createRecord', ['id' => (string)self::TEMPLATE_ID]);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testUpdateStoresALowercaseTypeUppercase(): void
    {
        $this->repository->expects($this->once())->method('updateRecord')
            ->with(self::RECORD_ID, 'www.[ZONE]', 'A', '192.0.2.10', 3600, 0)
            ->willReturn(true);

        $response = $this->send('PUT', 'updateRecord', ['template_id' => (string)self::TEMPLATE_ID, 'id' => (string)self::RECORD_ID]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $pathParameters
     */
    private function send(string $method, string $handler, array $pathParameters): JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneTemplate')->willReturn(true);
        $permissions->method('userHasPermission')->willReturn(false);
        $permissions->method('canWriteTemplateRecordType')->willReturn(true);

        $config = new FakeConfiguration(['dns' => ['ttl' => 3600]]);
        $validation = new ZoneTemplateRecordValidationService(
            new DnsValidatorRegistry($config, $this->createMock(DnsBackendProviderInterface::class))
        );

        $controller = $this->bareController(ZoneTemplateRecordsController::class);
        $this->injectBaseCollaborators($controller, $method, ['name' => 'www.[ZONE]', 'type' => 'a', 'content' => '192.0.2.10', 'ttl' => 3600]);
        $this->inject($controller, 'config', $config);
        $this->inject($controller, 'pathParameters', $pathParameters);
        $this->inject($controller, 'repository', $this->repository);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'recordValidationService', $validation);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);

        return $this->callHandler($controller, $handler);
    }
}
