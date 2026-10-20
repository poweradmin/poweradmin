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
use Poweradmin\Application\Controller\Api\V2\ZoneTemplatesController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * /api/v2/zone-templates driven through the real controller, including the
 * ueberuser guards on global templates, owner reassignment and audit logging.
 */
#[CoversClass(ZoneTemplatesController::class)]
class ZoneTemplatesControllerTest extends V2ControllerTestCase
{
    private const USER_ID = 1;

    /** @var ZoneTemplateRepositoryInterface&MockObject */
    private ZoneTemplateRepositoryInterface $repository;

    /** @var ApiPermissionService&MockObject */
    private ApiPermissionService $permissions;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->audit = $this->createMock(AuditService::class);
    }

    protected function stubServiceFactory(): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->audit);
        $factory->method('userRepository')->willReturn($this->stubUsers());

        return $factory;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $path
     */
    private function invoke(string $handler, string $method = 'GET', ?array $body = null, array $path = []): JsonResponse
    {
        $controller = $this->bareController(ZoneTemplatesController::class);
        $this->injectBaseCollaborators($controller, $method, $body);
        $this->inject($controller, 'pathParameters', $path);
        $this->inject($controller, 'repository', $this->repository);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);

        return $this->callHandler($controller, $handler);
    }

    private function ueberuser(bool $is): void
    {
        $this->permissions->method('userHasPermission')
            ->with(self::USER_ID, Permission::PERM_USER_IS_UEBERUSER)
            ->willReturn($is);
    }

    public function testListZoneTemplatesSuccess(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->expects($this->once())
            ->method('listZoneTemplates')
            ->with(self::USER_ID, false)
            ->willReturn([
                ['id' => 1, 'name' => 'Default', 'descr' => 'Default template', 'owner' => 0, 'zones_linked' => 3],
                ['id' => 2, 'name' => 'Custom', 'descr' => 'Custom template', 'owner' => 1, 'zones_linked' => 1],
            ]);

        $response = $this->invoke('listZoneTemplates');

        $this->assertSame(200, $response->getStatusCode());
        $content = $this->decode($response);
        $this->assertTrue($content['success']);
        $this->assertCount(2, $content['data']['templates']);
        $this->assertSame('Default', $content['data']['templates'][0]['name']);
        $this->assertSame('Default template', $content['data']['templates'][0]['description']);
        $this->assertTrue($content['data']['templates'][0]['is_global']);
        $this->assertSame(3, $content['data']['templates'][0]['zones_linked']);
        $this->assertFalse($content['data']['templates'][1]['is_global']);
    }

    public function testListZoneTemplatesFailureAnswers500WithoutLeakingTheException(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->expects($this->once())
            ->method('listZoneTemplates')
            ->willThrowException(new \RuntimeException('Database error'));

        $response = $this->invoke('listZoneTemplates');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
        $this->assertSame('Failed to fetch zone templates', $this->messageOf($response));
    }

    public function testListZoneTemplatesCatchesErrorsNotOnlyExceptions(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('listZoneTemplates')->willThrowException(new \TypeError('bad type'));

        $response = $this->invoke('listZoneTemplates');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Failed to fetch zone templates', $this->messageOf($response));
    }

    public function testListZoneTemplatesDeniedWithoutViewPermission(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(false);
        $this->repository->expects($this->never())->method('listZoneTemplates');

        $response = $this->invoke('listZoneTemplates');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to view zone templates', $this->messageOf($response));
    }

    public function testGetZoneTemplateSuccess(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->expects($this->once())
            ->method('getZoneTemplateDetails')
            ->with(1)
            ->willReturn(['id' => 1, 'name' => 'Default', 'descr' => 'Default template', 'owner' => 0]);
        $this->repository->expects($this->once())
            ->method('getZoneTemplateRecords')
            ->with(1)
            ->willReturn([
                ['id' => 1, 'name' => '[ZONE]', 'type' => 'SOA', 'content' => '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400', 'ttl' => 86400, 'prio' => 0],
            ]);

        $response = $this->invoke('getZoneTemplate', path: ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $content = $this->decode($response);
        $this->assertTrue($content['success']);
        $this->assertSame(1, $content['data']['template']['id']);
        $this->assertSame('Default template', $content['data']['template']['description']);
        $this->assertTrue($content['data']['template']['is_global']);
        $this->assertCount(1, $content['data']['template']['records']);
        $this->assertSame('SOA', $content['data']['template']['records'][0]['type']);
        $this->assertSame(0, $content['data']['template']['records'][0]['priority']);
    }

    public function testGetZoneTemplateStripsTxtQuotes(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('getZoneTemplateDetails')
            ->willReturn(['id' => 1, 'name' => 'Default', 'descr' => 'Default template', 'owner' => 0]);
        $this->repository->method('getZoneTemplateRecords')->willReturn([
            ['id' => 1, 'name' => '[ZONE]', 'type' => 'TXT', 'content' => '"v=spf1 -all"', 'ttl' => 3600, 'prio' => 0],
            ['id' => 2, 'name' => 'multi.[ZONE]', 'type' => 'TXT', 'content' => '"part1" "part2"', 'ttl' => 3600, 'prio' => 0],
            ['id' => 3, 'name' => '[ZONE]', 'type' => 'SPF', 'content' => '"v=spf1 -all"', 'ttl' => 3600, 'prio' => 0],
        ]);

        $response = $this->invoke('getZoneTemplate', path: ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $records = $this->decode($response)['data']['template']['records'];
        $this->assertSame('v=spf1 -all', $records[0]['content']);
        $this->assertSame('"part1" "part2"', $records[1]['content']);
        $this->assertSame('"v=spf1 -all"', $records[2]['content']);
    }

    public function testGetZoneTemplateNotFound(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->expects($this->once())
            ->method('getZoneTemplateDetails')
            ->with(999)
            ->willReturn(false);

        $response = $this->invoke('getZoneTemplate', path: ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
        $this->assertSame('Zone template not found', $this->messageOf($response));
    }

    public function testGetZoneTemplateOwnedBySomeoneElseIsForbidden(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->expects($this->once())
            ->method('getZoneTemplateDetails')
            ->with(2)
            ->willReturn(['id' => 2, 'name' => 'Private', 'descr' => 'Other user template', 'owner' => 99]);
        $this->repository->expects($this->never())->method('getZoneTemplateRecords');

        $response = $this->invoke('getZoneTemplate', path: ['id' => '2']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to view this zone template', $this->messageOf($response));
    }

    public function testGetZoneTemplateDeniedWithoutViewPermission(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(false);
        $this->repository->expects($this->never())->method('getZoneTemplateDetails');

        $response = $this->invoke('getZoneTemplate', path: ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateZoneTemplateSuccessIsOwnedByCallerAndAudited(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->with(self::USER_ID)->willReturn(true);
        $this->repository->method('zoneTemplateNameExists')->with('New Template')->willReturn(false);
        $this->repository->expects($this->once())
            ->method('createZoneTemplate')
            ->with('New Template', 'New template description', self::USER_ID, self::USER_ID)
            ->willReturn(5);
        $this->audit->expects($this->once())->method('logApiZoneTemplateAdd')->with(5, 'New Template');

        $response = $this->invoke('createZoneTemplate', 'POST', [
            'name' => 'New Template',
            'description' => 'New template description',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $content = $this->decode($response);
        $this->assertTrue($content['success']);
        $this->assertSame(5, $content['data']['id']);
        $this->assertSame('Zone template created successfully', $content['message']);
    }

    public function testCreateZoneTemplateMissingFields(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->repository->expects($this->never())->method('createZoneTemplate');

        $response = $this->invoke('createZoneTemplate', 'POST', ['name' => 'New Template']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
        $this->assertSame('Missing required fields: name, description', $this->messageOf($response));
    }

    public function testCreateZoneTemplateDuplicateName(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->repository->method('zoneTemplateNameExists')->with('Existing Template')->willReturn(true);
        $this->repository->expects($this->never())->method('createZoneTemplate');

        $response = $this->invoke('createZoneTemplate', 'POST', [
            'name' => 'Existing Template',
            'description' => 'Some description',
        ]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('A zone template with this name already exists', $this->messageOf($response));
    }

    public function testCreateZoneTemplateNoPermission(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->with(self::USER_ID)->willReturn(false);
        $this->repository->expects($this->never())->method('createZoneTemplate');

        $response = $this->invoke('createZoneTemplate', 'POST', [
            'name' => 'New Template',
            'description' => 'Some description',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
        $this->assertSame('You do not have permission to create zone templates', $this->messageOf($response));
    }

    public function testNonUeberuserCannotCreateAGlobalTemplate(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->expects($this->never())->method('createZoneTemplate');
        $this->audit->expects($this->never())->method('logApiZoneTemplateAdd');

        $response = $this->invoke('createZoneTemplate', 'POST', [
            'name' => 'Global',
            'description' => 'Wants to be global',
            'is_global' => true,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Only ueberusers can create global zone templates', $this->messageOf($response));
    }

    public function testUeberuserCreatesAGlobalTemplateWithOwnerZero(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->repository->expects($this->once())
            ->method('createZoneTemplate')
            ->with('Global', 'A global one', 0, self::USER_ID)
            ->willReturn(8);
        $this->audit->expects($this->once())->method('logApiZoneTemplateAdd')->with(8, 'Global');

        $response = $this->invoke('createZoneTemplate', 'POST', [
            'name' => 'Global',
            'description' => 'A global one',
            'is_global' => true,
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(8, $this->decode($response)['data']['id']);
    }

    public function testCreateZoneTemplateFailureAnswers500(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->repository->method('createZoneTemplate')->willThrowException(new \RuntimeException('SQL detail'));

        $response = $this->invoke('createZoneTemplate', 'POST', ['name' => 'N', 'description' => 'D']);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Failed to create zone template', $this->messageOf($response));
    }

    public function testUpdateZoneTemplateSuccessKeepsTheOwnerAndIsAudited(): void
    {
        $this->permissions->method('canEditZoneTemplate')->with(self::USER_ID)->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->with(1)->willReturn(true);
        $this->repository->method('getOwner')->with(1)->willReturn(self::USER_ID);
        $this->repository->method('zoneTemplateNameExists')->with('Updated Template', 1)->willReturn(false);
        $this->repository->expects($this->once())
            ->method('updateZoneTemplate')
            ->with(1, 'Updated Template', 'Updated description', null);
        $this->audit->expects($this->once())->method('logApiZoneTemplateEdit')->with(1, 'Updated Template');

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'Updated Template',
            'description' => 'Updated description',
        ], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->decode($response)['success']);
        $this->assertSame('Zone template updated successfully', $this->messageOf($response));
    }

    public function testUpdateZoneTemplateNotFound(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->repository->method('zoneTemplateExists')->with(999)->willReturn(false);
        $this->repository->expects($this->never())->method('updateZoneTemplate');

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'Updated Template',
            'description' => 'Updated description',
        ], ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
        $this->assertSame('Zone template not found', $this->messageOf($response));
    }

    public function testNonUeberuserCannotEditAGlobalTemplate(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(0);
        $this->repository->expects($this->never())->method('updateZoneTemplate');
        $this->audit->expects($this->never())->method('logApiZoneTemplateEdit');

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'Renamed',
            'description' => 'Changed',
        ], ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Only ueberusers can edit global zone templates', $this->messageOf($response));
    }

    public function testUeberuserCanEditAGlobalTemplate(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(0);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->repository->expects($this->once())
            ->method('updateZoneTemplate')
            ->with(1, 'Renamed', 'Changed', null);

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'Renamed',
            'description' => 'Changed',
        ], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateZoneTemplateOwnedBySomeoneElseIsForbiddenForANonUeberuser(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->expects($this->never())->method('updateZoneTemplate');

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y'], ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this zone template', $this->messageOf($response));
    }

    public function testNonUeberuserCannotMakeATemplateGlobal(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->repository->expects($this->never())->method('updateZoneTemplate');

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'X',
            'description' => 'Y',
            'is_global' => true,
        ], ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Only ueberusers can set templates as global', $this->messageOf($response));
    }

    public function testUeberuserMakingATemplateGlobalSetsOwnerZero(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->repository->expects($this->once())->method('updateZoneTemplate')->with(1, 'X', 'Y', 0);

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'X',
            'description' => 'Y',
            'is_global' => true,
        ], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testConvertingAGlobalTemplateToNonGlobalAssignsTheCaller(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(0);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->repository->expects($this->once())->method('updateZoneTemplate')->with(1, 'X', 'Y', self::USER_ID);

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'X',
            'description' => 'Y',
            'is_global' => false,
        ], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNonGlobalStayingNonGlobalPreservesTheOwner(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->repository->expects($this->once())->method('updateZoneTemplate')->with(1, 'X', 'Y', null);

        $response = $this->invoke('updateZoneTemplate', 'PUT', [
            'name' => 'X',
            'description' => 'Y',
            'is_global' => false,
        ], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateZoneTemplateDuplicateName(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->repository->method('zoneTemplateNameExists')->with('Taken', 1)->willReturn(true);
        $this->repository->expects($this->never())->method('updateZoneTemplate');

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'Taken', 'description' => 'Y'], ['id' => '1']);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testDeleteZoneTemplateSuccessIsAudited(): void
    {
        $this->permissions->method('canEditZoneTemplate')->with(self::USER_ID)->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->with(1)->willReturn(true);
        $this->repository->method('getOwner')->with(1)->willReturn(self::USER_ID);
        $this->repository->expects($this->once())->method('deleteZoneTemplate')->with(1)->willReturn(true);
        $this->audit->expects($this->once())->method('logApiZoneTemplateDelete')->with(1);

        $response = $this->invoke('deleteZoneTemplate', 'DELETE', null, ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->decode($response)['success']);
        $this->assertSame('Zone template deleted successfully', $this->messageOf($response));
    }

    public function testDeleteZoneTemplateNotFound(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->repository->method('zoneTemplateExists')->with(999)->willReturn(false);
        $this->repository->expects($this->never())->method('deleteZoneTemplate');

        $response = $this->invoke('deleteZoneTemplate', 'DELETE', null, ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
        $this->assertSame('Zone template not found', $this->messageOf($response));
    }

    public function testDeleteGlobalTemplateRequiresUeberuser(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(0);
        $this->repository->expects($this->never())->method('deleteZoneTemplate');

        $response = $this->invoke('deleteZoneTemplate', 'DELETE', null, ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['success']);
        $this->assertSame('Only ueberusers can delete global zone templates', $this->messageOf($response));
    }

    public function testDeleteZoneTemplateOwnedBySomeoneElseIsForbiddenForANonUeberuser(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->expects($this->never())->method('deleteZoneTemplate');

        $response = $this->invoke('deleteZoneTemplate', 'DELETE', null, ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to delete this zone template', $this->messageOf($response));
    }
}
