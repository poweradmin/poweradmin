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
use Poweradmin\Application\Controller\Api\V2\ZoneTemplatesController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Template\ZoneTemplateGroupShares;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteResult;
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

    /** @var ZoneTemplateGroupShares&MockObject */
    private ZoneTemplateGroupShares $shares;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->shares = $this->createMock(ZoneTemplateGroupShares::class);
    }

    protected function stubServiceFactory(): ControllerServiceFactory
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('auditService')->willReturn($this->audit);
        $factory->method('zoneTemplateGroupShares')->willReturn($this->shares);
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

    public function testGetZoneTemplateSharedWithTheCallersGroupListsOnlyTheirGroups(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('getZoneTemplateDetails')->willReturn(['id' => 2, 'name' => 'Shared', 'descr' => 'Team template', 'owner' => 99]);
        $this->repository->method('isSharedWithUser')->with(2, self::USER_ID)->willReturn(true);
        $this->repository->method('getZoneTemplateRecords')->willReturn([]);
        $this->repository->expects($this->once())->method('listGroupsForTemplates')
            ->with([2], self::USER_ID)
            ->willReturn([2 => [['id' => 3, 'name' => 'Ops']]]);

        $response = $this->invoke('getZoneTemplate', path: ['id' => '2']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['id' => 3, 'name' => 'Ops']], $this->decode($response)['data']['template']['groups']);
    }

    public function testListZoneTemplatesCarriesTheSharedGroups(): void
    {
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('listZoneTemplates')->willReturn([
            ['id' => 2, 'name' => 'Shared', 'descr' => '', 'owner' => 99, 'zones_linked' => 0, 'groups' => [['id' => 3, 'name' => 'Ops']]],
        ]);

        $response = $this->invoke('listZoneTemplates');

        $this->assertSame([['id' => 3, 'name' => 'Ops']], $this->decode($response)['data']['templates'][0]['groups']);
    }

    public function testGroupMemberMayUpdateASharedTemplateWithoutTakingItOver(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->method('isSharedWithUser')->with(1, self::USER_ID)->willReturn(true);
        $this->repository->expects($this->once())->method('updateZoneTemplate')->with(1, 'X', 'Y', null);

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y', 'is_global' => false], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGroupMemberMayNotDeleteASharedTemplate(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->method('isSharedWithUser')->willReturn(true);
        $this->repository->expects($this->never())->method('deleteZoneTemplate');

        $response = $this->invoke('deleteZoneTemplate', 'DELETE', null, ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreatingATemplateWithGroupsSharesItWithTheAcceptedOnes(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->shares->expects($this->once())->method('groupsForNewTemplate')->with([4, 3], self::USER_ID)->willReturn([3, 4]);
        $this->repository->expects($this->once())->method('createZoneTemplate')->with('T', 'D', self::USER_ID, self::USER_ID, [3, 4])->willReturn(5);

        $response = $this->invoke('createZoneTemplate', 'POST', ['name' => 'T', 'description' => 'D', 'groups' => [4, 3]]);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testCreatingATemplateWithoutGroupsLeavesTheShareServiceAlone(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->repository->method('zoneTemplateNameExists')->willReturn(false);
        $this->shares->expects($this->never())->method('groupsForNewTemplate');
        $this->repository->expects($this->once())->method('createZoneTemplate')->with('T', 'D', self::USER_ID, self::USER_ID, [])->willReturn(5);

        $this->assertSame(201, $this->invoke('createZoneTemplate', 'POST', ['name' => 'T', 'description' => 'D'])->getStatusCode());
    }

    public function testARefusedShareOnCreateCreatesNothing(): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->shares->method('groupsForNewTemplate')->willReturn(ZoneTemplateWriteResult::forbidden('You can only share a zone template with groups you belong to.'));
        $this->repository->expects($this->never())->method('createZoneTemplate');

        $response = $this->invoke('createZoneTemplate', 'POST', ['name' => 'T', 'description' => 'D', 'groups' => [9]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You can only share a zone template with groups you belong to.', $this->messageOf($response));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badGroupsOnCreate(): array
    {
        return [
            'not a list' => [['groups' => '3'], 'groups must be an array of group ids'],
            'null' => [['groups' => null], 'groups must be an array of group ids'],
            'an object' => [['groups' => ['a' => 3]], 'groups must be an array of group ids'],
            'global' => [['groups' => [3], 'is_global' => true], 'A global zone template cannot be shared with groups'],
        ];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('badGroupsOnCreate')]
    public function testMalformedOrGlobalGroupsOnCreateAre400(array $extra, string $message): void
    {
        $this->permissions->method('canCreateZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->shares->expects($this->never())->method('groupsForNewTemplate');
        $this->repository->expects($this->never())->method('createZoneTemplate');

        $response = $this->invoke('createZoneTemplate', 'POST', ['name' => 'T', 'description' => 'D'] + $extra);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, $this->messageOf($response));
    }

    public function testGroupMemberSendingGroupsOnUpdateIsRefusedBeforeAnythingIsSaved(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->method('isSharedWithUser')->willReturn(true);
        $this->shares->method('refusal')->with(1, [3], self::USER_ID)
            ->willReturn(ZoneTemplateWriteResult::forbidden('You do not have the permission to share this zone template.'));
        $this->repository->expects($this->never())->method('updateZoneTemplate');
        $this->shares->expects($this->never())->method('update');

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y', 'groups' => [3]], ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheOwnerSendingGroupsOnUpdateReplacesTheShares(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->shares->method('refusal')->willReturn(null);
        $this->repository->expects($this->once())->method('updateZoneTemplate')->with(1, 'X', 'Y', null);
        $this->shares->expects($this->once())->method('update')->with(1, [], self::USER_ID)->willReturn(ZoneTemplateWriteResult::ok());

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y', 'groups' => []], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnUpdateWithoutGroupsKeepsTheShares(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->shares->expects($this->never())->method('refusal');
        $this->shares->expects($this->never())->method('update');

        $this->assertSame(200, $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y'], ['id' => '1'])->getStatusCode());
    }

    public function testMakingATemplateGlobalWithGroupsIs400(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->repository->expects($this->never())->method('updateZoneTemplate');

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y', 'is_global' => true, 'groups' => [3]], ['id' => '1']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('A global zone template cannot be shared with groups', $this->messageOf($response));
    }

    public function testAUeberuserMakingAGlobalTemplatePrivateSharesItInTheSameCall(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(true);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(0);
        $this->shares->expects($this->once())->method('refusal')->with(1, [3], self::USER_ID)->willReturn(null);
        $this->repository->expects($this->once())->method('updateZoneTemplate')->with(1, 'X', 'Y', self::USER_ID);
        $this->shares->expects($this->once())->method('update')->with(1, [3], self::USER_ID)->willReturn(ZoneTemplateWriteResult::ok());

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y', 'is_global' => false, 'groups' => [3]], ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testANullGroupsFieldOnUpdateIs400(): void
    {
        $this->permissions->method('canEditZoneTemplate')->willReturn(true);
        $this->ueberuser(false);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getOwner')->willReturn(self::USER_ID);
        $this->repository->expects($this->never())->method('updateZoneTemplate');

        $response = $this->invoke('updateZoneTemplate', 'PUT', ['name' => 'X', 'description' => 'Y', 'groups' => null], ['id' => '1']);

        $this->assertSame(400, $response->getStatusCode());
    }
}
