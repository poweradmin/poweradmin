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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\PermissionServiceTestCase;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * The add-zone forms drop the owner or groups the ownership mode disallows
 * instead of refusing, and word refusals for the page rather than the API.
 */
#[CoversClass(ZoneOwnershipFormResolver::class)]
class ZoneOwnershipFormResolverTest extends PermissionServiceTestCase
{

    private const CALLER_ID = 7;
    private const USERS = [
        ['id' => 7, 'username' => 'client'],
        ['id' => 9, 'username' => 'other'],
    ];

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testGroupsOnlyModeIgnoresTheOwnerField(): void
    {
        $_POST = ['owner' => '9', 'groups' => ['3', '3']];

        $result = $this->resolver('groups_only', [3])->resolve(new Request(), self::CALLER_ID);

        $this->assertFalse($result->hasError());
        $this->assertNull($result->owner);
        $this->assertSame([3], $result->groupIds);
    }

    public function testUsersOnlyModeIgnoresTheGroupsField(): void
    {
        $_POST = ['owner' => (string)self::CALLER_ID, 'groups' => ['3']];

        $result = $this->resolver('users_only')->resolve(new Request(), self::CALLER_ID);

        $this->assertFalse($result->hasError());
        $this->assertSame(self::CALLER_ID, $result->owner);
        $this->assertSame([], $result->groupIds);
    }

    public function testAnArrayOwnerCountsAsNoOwner(): void
    {
        // A tampered owner[] field must not reach filter_var as an array
        $result = $this->resolver('both')->resolveInputs(['9'], null, self::CALLER_ID);

        $this->assertSame(ZoneOwnershipResolution::NO_OWNER, $result->code);
    }

    public function testAMalformedOwnerCountsAsNoOwner(): void
    {
        $_POST = ['owner' => 'abc'];

        $result = $this->resolver('both')->resolve(new Request(), self::CALLER_ID);

        $this->assertSame(ZoneOwnershipResolution::NO_OWNER, $result->code);
        $this->assertSame('At least one user or group must be selected as owner.', ZoneOwnershipFormResolver::errorMessage($result));
    }

    public function testRefusalsNameTheOffendingGroups(): void
    {
        $_POST = ['owner' => (string)self::CALLER_ID, 'groups' => ['3', '9']];

        $result = $this->resolver('both', [3])->resolve(new Request(), self::CALLER_ID);

        $this->assertSame(ZoneOwnershipResolution::GROUPS_NOT_MEMBER, $result->code);
        $this->assertSame('You can only assign groups you are a member of (disallowed: 9)', ZoneOwnershipFormResolver::errorMessage($result));
    }

    public function testAnUnknownOwnerIsNamedInTheRefusal(): void
    {
        $_POST = ['owner' => '42'];

        $result = $this->resolver('both', adminCaller: true)->resolve(new Request(), self::CALLER_ID);

        $this->assertSame(ZoneOwnershipResolution::UNKNOWN_OWNER, $result->code);
        $this->assertSame('Unknown user ID: 42', ZoneOwnershipFormResolver::errorMessage($result));
    }

    public function testTheBlockerIsWordedForThePage(): void
    {
        $this->assertNull($this->resolver('both')->blocker(self::CALLER_ID));
        $this->assertStringContainsString('not a member of any group', (string)$this->resolver('groups_only')->blocker(self::CALLER_ID));
    }

    public function testViewOthersGrantOffersEveryUser(): void
    {
        $resolver = $this->resolver('both', callerPermissions: [Permission::PERM_USER_VIEW_OTHERS]);

        $this->assertSame(self::USERS, $resolver->selectableOwners(self::USERS, self::CALLER_ID));
    }

    public function testWithoutTheGrantOnlyTheCallerIsOffered(): void
    {
        $this->assertSame([['id' => 7, 'username' => 'client']], $this->resolver('both')->selectableOwners(self::USERS, self::CALLER_ID));
        $this->assertSame([], $this->resolver('both')->selectableOwners(self::USERS, 42));
    }

    public function testAssigningOthersNeedsBothTheCreateRuleAndViewOthers(): void
    {
        $both = [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS, Permission::PERM_USER_VIEW_OTHERS];

        $this->assertSame(self::USERS, $this->resolver('both', callerPermissions: $both)->assignableOwners(self::USERS, self::CALLER_ID));
        $this->assertSame([['id' => 7, 'username' => 'client']], $this->resolver('both', callerPermissions: [Permission::PERM_USER_VIEW_OTHERS])->assignableOwners(self::USERS, self::CALLER_ID));
        $this->assertSame([['id' => 7, 'username' => 'client']], $this->resolver('both', callerPermissions: [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS])->assignableOwners(self::USERS, self::CALLER_ID));
    }

    public function testUnmappedCodesFallBackToTheResolutionText(): void
    {
        $result = ZoneOwnershipResolution::error('api wording', Refusal::INVALID_INPUT, ZoneOwnershipResolution::INVALID_INPUT);

        $this->assertSame('api wording', ZoneOwnershipFormResolver::errorMessage($result));
    }

    /**
     * @param int[] $memberOf groups the caller belongs to; every requested group exists
     */
    private function resolver(string $mode, array $memberOf = [], bool $adminCaller = false, array $callerPermissions = []): ZoneOwnershipFormResolver
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->with('dns', 'zone_ownership_mode', 'both')->willReturn($mode);
        $ownershipMode = new ZoneOwnershipModeService($config);

        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('findExistingIds')->willReturnCallback(fn(array $ids): array => $ids);
        $groups->method('getGroupIdsForUser')->willReturn($memberOf);

        // No user besides the caller exists, and the caller is never looked up.
        $users = $this->scriptedUserRepository([self::CALLER_ID => $callerPermissions], adminUserIds: $adminCaller ? [self::CALLER_ID] : []);
        $permissions = new PermissionService($users);

        return new ZoneOwnershipFormResolver(
            $ownershipMode,
            new ZoneCreateOwnershipResolver($ownershipMode, $permissions, $groups, $users),
            $permissions
        );
    }
}
