<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\SearchController;
use ReflectionClass;

/**
 * Covers per-row permission resolution in search results (issue #1200): direct
 * vs. group ownership must both grant edit/delete eligibility.
 */
class SearchControllerPermissionFlagsTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reflection = new ReflectionClass(SearchController::class);
    }

    private function createController(): SearchController
    {
        return $this->getMockBuilder(SearchController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();
    }

    private function invokeCanActOnZone(SearchController $controller, array $args): bool
    {
        $method = $this->reflection->getMethod('canActOnZone');
        return $method->invokeArgs($controller, $args);
    }

    private function invokeMerge(SearchController $controller, array $a, array $b): array
    {
        $method = $this->reflection->getMethod('mergePermissionSources');
        return $method->invokeArgs($controller, [$a, $b]);
    }

    public function testCanActAlwaysGrantsForAllPermission(): void
    {
        $controller = $this->createController();

        $result = $this->invokeCanActOnZone($controller, [
            42,
            7,
            'all',
            ['has_direct' => false, 'group_ids' => []],
            [],
            [],
        ]);

        $this->assertTrue($result);
    }

    public function testCanActDeniesForNonePermission(): void
    {
        $controller = $this->createController();

        $result = $this->invokeCanActOnZone($controller, [
            42,
            7,
            'none',
            ['has_direct' => true, 'group_ids' => [1]],
            [42 => [7]],
            [42 => [1]],
        ]);

        $this->assertFalse($result);
    }

    public function testCanActAllowsDirectOwnerWithDirectGrant(): void
    {
        $controller = $this->createController();

        $result = $this->invokeCanActOnZone($controller, [
            42,
            7,
            'own',
            ['has_direct' => true, 'group_ids' => []],
            [42 => [7]],
            [],
        ]);

        $this->assertTrue($result);
    }

    public function testCanActDeniesDirectOwnerWithoutDirectGrant(): void
    {
        // User directly owns the zone, but their direct permission template
        // does not grant the action; group sources do not match either.
        $controller = $this->createController();

        $result = $this->invokeCanActOnZone($controller, [
            42,
            7,
            'own',
            ['has_direct' => false, 'group_ids' => [3]],
            [42 => [7]],
            [42 => [9]],
        ]);

        $this->assertFalse($result);
    }

    public function testCanActAllowsGroupOwnerWithGroupGrant(): void
    {
        $controller = $this->createController();

        $result = $this->invokeCanActOnZone($controller, [
            42,
            7,
            'own',
            ['has_direct' => false, 'group_ids' => [3, 5]],
            [42 => [99]],
            [42 => [5]],
        ]);

        $this->assertTrue($result);
    }

    public function testCanActDeniesGroupOwnerWhenUserGroupsDoNotMatch(): void
    {
        $controller = $this->createController();

        $result = $this->invokeCanActOnZone($controller, [
            42,
            7,
            'own',
            ['has_direct' => false, 'group_ids' => [1, 2]],
            [],
            [42 => [99]],
        ]);

        $this->assertFalse($result);
    }

    public function testCanActAcceptsOwnAsClientLikeOwn(): void
    {
        $controller = $this->createController();

        $result = $this->invokeCanActOnZone($controller, [
            42,
            7,
            'own_as_client',
            ['has_direct' => true, 'group_ids' => []],
            [42 => [7]],
            [],
        ]);

        $this->assertTrue($result);
    }

    public function testMergePermissionSourcesUnionsDirectAndGroupSources(): void
    {
        $controller = $this->createController();

        $merged = $this->invokeMerge(
            $controller,
            ['has_direct' => true, 'group_ids' => [1, 2]],
            ['has_direct' => false, 'group_ids' => [2, 3]]
        );

        $this->assertTrue($merged['has_direct']);
        sort($merged['group_ids']);
        $this->assertSame([1, 2, 3], $merged['group_ids']);
    }

    public function testMergePermissionSourcesPreservesEmpty(): void
    {
        $controller = $this->createController();

        $merged = $this->invokeMerge(
            $controller,
            ['has_direct' => false, 'group_ids' => []],
            ['has_direct' => false, 'group_ids' => []]
        );

        $this->assertFalse($merged['has_direct']);
        $this->assertSame([], $merged['group_ids']);
    }
}
