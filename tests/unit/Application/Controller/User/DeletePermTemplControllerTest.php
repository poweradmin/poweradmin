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

namespace Poweradmin\Tests\Unit\Application\Controller\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\User\DeletePermTemplController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\PermissionTemplateDeleteResult;
use Poweradmin\Domain\Repository\PermissionTemplateRepositoryInterface;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes the delete-permission-template page: what the list page
 * shows after a confirmed delete, and the single message a refused one leaves.
 */
#[CoversClass(DeletePermTemplController::class)]
class DeletePermTemplControllerTest extends SeamControllerTestCase
{
    private const TEMPLATE_ID = 7;

    /** @var PermissionTemplateRepositoryInterface&MockObject */
    private PermissionTemplateRepositoryInterface $templates;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $this->templates = $this->createMock(PermissionTemplateRepositoryInterface::class);
        $this->templates->method('getPermissionTemplateDetails')->willReturn(['id' => self::TEMPLATE_ID, 'name' => 'Spare']);

        $this->audit = $this->createMock(AuditService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('permissionTemplateRepository')->willReturn($this->templates);
        $this->factory->method('auditService')->willReturn($this->audit);
    }

    private function runConfirmedDelete(): RequestHalted
    {
        $this->post(['confirm' => '1', 'id' => (string)self::TEMPLATE_ID]);
        $controller = new TestableDeletePermTemplController(
            ['id' => (string)self::TEMPLATE_ID] + $this->requestData(),
            $this->environment($this->configure())
        );

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected a redirect to the template list.');
    }

    public function testADeletedTemplateIsAuditedAndTheListPageShowsTheSuccess(): void
    {
        $this->templates->method('deletePermissionTemplate')->with(self::TEMPLATE_ID)->willReturn(PermissionTemplateDeleteResult::DELETED);
        $this->audit->expects($this->once())->method('logPermTemplateDelete')->with(self::TEMPLATE_ID, 'Spare');

        $halt = $this->runConfirmedDelete();

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/permissions/templates', $halt->target);
        $this->assertSame([['success', 'The permission template has been deleted successfully.']], $this->messagesFor('list_perm_templ'));
        $this->assertSame([], $this->messagesFor('system'));
    }

    /**
     * The controller asks the factory for the repository on first use and keeps
     * that instance for the rest of the request, so the lookup and the delete
     * hit the same repository.
     */
    public function testTheTemplateRepositoryIsResolvedOnceForTheRequest(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $this->templates->method('deletePermissionTemplate')->willReturn(PermissionTemplateDeleteResult::DELETED);

        $this->factory = $this->createMock(ControllerServiceFactory::class);
        $this->factory->expects($this->once())->method('permissionTemplateRepository')->willReturn($this->templates);
        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('auditService')->willReturn($this->audit);

        $halt = $this->runConfirmedDelete();

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
    }

    /** @return array<string, array{0: PermissionTemplateDeleteResult, 1: string}> */
    public static function refusalProvider(): array
    {
        return [
            'held by a user' => [PermissionTemplateDeleteResult::IN_USE_BY_USERS, 'This template is assigned to at least one user.'],
            'held by a group' => [PermissionTemplateDeleteResult::IN_USE_BY_GROUPS, 'This template is assigned to at least one group.'],
            'held by both' => [PermissionTemplateDeleteResult::IN_USE_BY_BOTH, 'This template is assigned to at least one user and one group.'],
        ];
    }

    #[DataProvider('refusalProvider')]
    public function testARefusedDeleteShowsOneErrorNamingTheHolderOnTheListPage(PermissionTemplateDeleteResult $result, string $expected): void
    {
        $this->templates->method('deletePermissionTemplate')->willReturn($result);
        $this->audit->expects($this->never())->method('logPermTemplateDelete');

        $halt = $this->runConfirmedDelete();

        $this->assertSame('/permissions/templates', $halt->target);
        $this->assertSame([['error', $expected]], $this->messagesFor('list_perm_templ'));
        $this->assertSame([], $this->messagesFor('system'));
    }
}
