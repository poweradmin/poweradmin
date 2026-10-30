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

namespace Poweradmin\Tests\Unit\Domain\Service\Template;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplateGroupShares;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteResult;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\SessionActor;

/**
 * The owner shares a template with groups they belong to and a ueberuser with any
 * group. A group a ueberuser added stays when the owner saves, and nobody else may
 * change the list.
 */
class ZoneTemplateGroupSharesTest extends TestCase
{
    private const OWNER = 5;
    private const TEMPLATE = 10;

    /** @var ZoneTemplateRepositoryInterface&MockObject */
    private ZoneTemplateRepositoryInterface $repository;
    /** @var UserGroupLookupInterface&MockObject */
    private UserGroupLookupInterface $groups;
    /** @var list<string> */
    private array $granted = [Permission::PERM_ZONE_TEMPL_EDIT];
    private int $templateOwner = self::OWNER;
    /** @var list<array{id: int, name: string}> */
    private array $stored = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->repository->method('getOwner')->willReturnCallback(fn(): int => $this->templateOwner);
        $this->repository->method('listGroupsForTemplates')
            ->willReturnCallback(fn(): array => $this->stored === [] ? [] : [self::TEMPLATE => $this->stored]);
        $this->groups = $this->createMock(UserGroupLookupInterface::class);
        $this->groups->method('findByUserId')->willReturn([$this->group(3, 'Ops'), $this->group(4, 'Dev')]);
        $this->groups->method('findAll')->willReturn([$this->group(3, 'Ops'), $this->group(4, 'Dev'), $this->group(9, 'Billing')]);
    }

    public function testOwnerSharesWithTheirOwnGroups(): void
    {
        $this->repository->expects($this->once())->method('replaceTemplateGroups')->with(self::TEMPLATE, [3, 4]);

        $this->assertTrue($this->shares()->update(self::TEMPLATE, ['4', '3'], self::OWNER)->success);
    }

    public function testOwnerCannotShareWithAGroupTheyAreNotIn(): void
    {
        $this->repository->expects($this->never())->method('replaceTemplateGroups');

        $result = $this->shares()->update(self::TEMPLATE, ['3', '9'], self::OWNER);
        $this->assertFalse($result->success);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
    }

    public function testRefusalChecksWithoutWriting(): void
    {
        $this->repository->expects($this->never())->method('replaceTemplateGroups');

        $this->assertSame(Refusal::FORBIDDEN, $this->shares()->refusal(self::TEMPLATE, ['9'], self::OWNER)?->refusal);
        $this->assertNull($this->shares()->refusal(self::TEMPLATE, ['3'], self::OWNER));
    }

    public function testAGroupAUeberuserAddedSurvivesTheOwnersSave(): void
    {
        $this->stored = [['id' => 3, 'name' => 'Ops'], ['id' => 9, 'name' => 'Billing']];
        $this->repository->expects($this->once())->method('replaceTemplateGroups')->with(self::TEMPLATE, [4, 9]);

        // The owner unticks Ops and ticks Dev; Billing is not theirs to remove
        $this->assertTrue($this->shares()->update(self::TEMPLATE, ['4'], self::OWNER)->success);
    }

    public function testUnchangedListWritesNothing(): void
    {
        $this->stored = [['id' => 3, 'name' => 'Ops']];
        $this->repository->expects($this->never())->method('replaceTemplateGroups');

        $this->assertTrue($this->shares()->update(self::TEMPLATE, ['3'], self::OWNER)->success);
    }

    public function testGroupMemberMayNotChangeTheList(): void
    {
        $this->templateOwner = 7;
        $this->repository->expects($this->never())->method('replaceTemplateGroups');

        $this->assertSame(Refusal::FORBIDDEN, $this->shares()->update(self::TEMPLATE, ['3'], self::OWNER)->refusal);
    }

    public function testUeberuserSharesWithAnyGroup(): void
    {
        $this->granted = [Permission::PERM_USER_IS_UEBERUSER];
        $this->templateOwner = 7;
        $this->repository->expects($this->once())->method('replaceTemplateGroups')->with(self::TEMPLATE, [9]);

        $this->assertTrue($this->shares()->update(self::TEMPLATE, ['9'], self::OWNER)->success);
    }

    public function testMalformedGroupIdIsRefused(): void
    {
        $this->repository->expects($this->never())->method('replaceTemplateGroups');

        $this->assertSame(Refusal::INVALID_INPUT, $this->shares()->update(self::TEMPLATE, ['3x'], self::OWNER)->refusal);
        $this->assertSame(Refusal::INVALID_INPUT, $this->shares()->update(self::TEMPLATE, [['3']], self::OWNER)->refusal);
    }

    public function testANewTemplateIsSharedWithTheCreatorsOwnGroups(): void
    {
        $this->assertSame([3, 4], $this->shares()->groupsForNewTemplate(['4', '3', '4'], self::OWNER));
        $this->assertSame([], $this->shares()->groupsForNewTemplate([], self::OWNER));
    }

    public function testANewTemplateIsNotSharedWithAGroupTheCreatorIsNotIn(): void
    {
        $result = $this->shares()->groupsForNewTemplate(['3', '9'], self::OWNER);

        $this->assertInstanceOf(ZoneTemplateWriteResult::class, $result);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
    }

    public function testACreatorWithoutTemplateEditRightsCannotShare(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_ADD];

        $this->assertSame(Refusal::FORBIDDEN, $this->shares()->groupsForNewTemplate(['3'], self::OWNER)->refusal);
        $this->assertSame([], $this->shares()->groupsForNewTemplate([], self::OWNER));
    }

    public function testAUeberuserSharesANewTemplateWithAnyGroup(): void
    {
        $this->granted = [Permission::PERM_USER_IS_UEBERUSER];

        $this->assertSame([9], $this->shares()->groupsForNewTemplate(['9'], self::OWNER));
    }

    public function testAMalformedGroupIdOnANewTemplateIsRefused(): void
    {
        $this->assertSame(Refusal::INVALID_INPUT, $this->shares()->groupsForNewTemplate(['0'], self::OWNER)->refusal);
    }

    private function shares(): ZoneTemplateGroupShares
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));
        $actor = new SessionActor(new ArraySession([SessionKeys::USERID => self::OWNER]));

        return new ZoneTemplateGroupShares(
            $this->repository,
            new ZoneTemplateAccessPolicy($this->repository, $permissions, $actor),
            $this->groups
        );
    }

    private function group(int $id, string $name): UserGroup
    {
        return new UserGroup($id, $name, null, 1);
    }
}
