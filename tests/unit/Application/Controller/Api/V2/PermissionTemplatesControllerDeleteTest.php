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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\PermissionTemplatesController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\User\PermissionTemplateDeleteResult;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * DELETE /api/v2/permission-templates/{id}: the 409 wording is public contract
 * whichever holder blocks the delete, and a completed delete is audited.
 */
#[CoversClass(PermissionTemplatesController::class)]
class PermissionTemplatesControllerDeleteTest extends V2ControllerTestCase
{
    private const TEMPLATE_ID = 7;
    private const USER_ID = 5;

    /** @var PermissionTemplateRepositoryInterface&MockObject */
    private PermissionTemplateRepositoryInterface $templates;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        $this->templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $this->templates->method('getPermissionTemplateDetails')->willReturn(['id' => self::TEMPLATE_ID, 'name' => 'Spare']);
        $this->audit = $this->createMock(AuditService::class);
    }

    protected function stubServiceFactory(): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->audit);
        $factory->method('userRepository')->willReturn($this->stubUsers());

        return $factory;
    }

    private function invokeDelete(): JsonResponse
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('userHasPermission')->willReturn(true);

        $controller = $this->bareController(PermissionTemplatesController::class);
        $this->injectBaseCollaborators($controller, 'DELETE');
        $this->inject($controller, 'pathParameters', ['id' => (string)self::TEMPLATE_ID]);
        $this->inject($controller, 'permissionTemplateRepository', $this->templates);
        $this->inject($controller, 'apiPermissionService', $permissions);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);

        return $this->callHandler($controller, 'deletePermissionTemplate');
    }

    public function testADeletedTemplateIsAuditedAndAnswers200(): void
    {
        $this->templates->method('deletePermissionTemplate')->with(self::TEMPLATE_ID)->willReturn(PermissionTemplateDeleteResult::DELETED);
        $this->audit->expects($this->once())->method('logPermTemplateDelete')->with(self::TEMPLATE_ID, 'Spare');

        $response = $this->invokeDelete();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Permission template deleted successfully', $this->messageOf($response));
    }

    /** @return array<string, array{0: PermissionTemplateDeleteResult}> */
    public static function inUseProvider(): array
    {
        return [
            'held by a user' => [PermissionTemplateDeleteResult::IN_USE_BY_USERS],
            'held by a group' => [PermissionTemplateDeleteResult::IN_USE_BY_GROUPS],
            'held by both' => [PermissionTemplateDeleteResult::IN_USE_BY_BOTH],
        ];
    }

    #[DataProvider('inUseProvider')]
    public function testATemplateStillInUseKeepsThe409Contract(PermissionTemplateDeleteResult $result): void
    {
        $this->templates->method('deletePermissionTemplate')->willReturn($result);
        $this->audit->expects($this->never())->method('logPermTemplateDelete');

        $response = $this->invokeDelete();

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Cannot delete permission template - it is assigned to one or more users', $this->messageOf($response));
    }
}
