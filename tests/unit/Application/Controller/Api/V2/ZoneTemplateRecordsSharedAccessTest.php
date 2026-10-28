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
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use ReflectionMethod;

/**
 * Template record endpoints follow the template's sharing: a member of a group the
 * template is shared with reads and edits its records, nobody else does, and
 * sharing never opens a global template for editing.
 */
#[CoversClass(ZoneTemplateRecordsController::class)]
class ZoneTemplateRecordsSharedAccessTest extends V2ControllerTestCase
{
    private const USER_ID = 1;
    private const TEMPLATE_ID = 5;

    /** @var ZoneTemplateRepositoryInterface&MockObject */
    private ZoneTemplateRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->repository->method('isOwner')->willReturn(false);
    }

    public function testGroupMemberReadsAndEditsTheRecords(): void
    {
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->method('isSharedWithUser')->with(self::TEMPLATE_ID, self::USER_ID)->willReturn(true);

        $this->assertTrue($this->check('canViewTemplate'));
        $this->assertTrue($this->check('canEditTemplate'));
    }

    public function testUnsharedForeignTemplateStaysClosed(): void
    {
        $this->repository->method('getOwner')->willReturn(99);
        $this->repository->method('isSharedWithUser')->willReturn(false);

        $this->assertFalse($this->check('canViewTemplate'));
        $this->assertFalse($this->check('canEditTemplate'));
    }

    public function testSharingDoesNotOpenAGlobalTemplateForEditing(): void
    {
        $this->repository->method('getOwner')->willReturn(0);
        $this->repository->method('isSharedWithUser')->willReturn(true);

        $this->assertTrue($this->check('canViewTemplate'));
        $this->assertFalse($this->check('canEditTemplate'));
    }

    public function testMissingTemplateIsNeitherReadableNorEditable(): void
    {
        $this->repository->method('getOwner')->willReturn(null);
        $this->repository->expects($this->never())->method('isSharedWithUser');

        $this->assertFalse($this->check('canViewTemplate'));
        $this->assertFalse($this->check('canEditTemplate'));
    }

    private function check(string $method): bool
    {
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canViewZoneTemplates')->willReturn(true);
        $permissions->method('canEditZoneTemplate')->willReturn(true);
        $permissions->method('userHasPermission')->willReturn(false);

        $controller = $this->bareController(ZoneTemplateRecordsController::class);
        $this->inject($controller, 'repository', $this->repository);
        $this->inject($controller, 'apiPermissionService', $permissions);

        return (bool)(new ReflectionMethod($controller, $method))->invoke($controller, self::USER_ID, self::TEMPLATE_ID);
    }
}
