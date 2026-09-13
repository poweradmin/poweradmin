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

    public function testCanActAlwaysGrantsForAllPermission(): void
    {
        $this->assertTrue($this->invokeCanActOnZone($this->createController(), [42, 7, 'all', [], [], []]));
    }

    public function testCanActDeniesForNonePermission(): void
    {
        // The level already says the user holds no edit grant; ownership cannot help.
        $this->assertFalse($this->invokeCanActOnZone($this->createController(), [42, 7, 'none', [1], [42 => [7]], [42 => [1]]]));
    }

    public function testCanActAllowsDirectOwner(): void
    {
        $this->assertTrue($this->invokeCanActOnZone($this->createController(), [42, 7, 'own', [], [42 => [7]], []]));
    }

    public function testCanActAllowsOwnerViaAnyGroup(): void
    {
        // Union rule: the grant may come from one group while another group owns the zone.
        $this->assertTrue($this->invokeCanActOnZone($this->createController(), [42, 7, 'own', [3, 5], [42 => [99]], [42 => [5]]]));
    }

    public function testCanActDeniesNonOwner(): void
    {
        $this->assertFalse($this->invokeCanActOnZone($this->createController(), [42, 7, 'own', [1, 2], [42 => [99]], [42 => [9]]]));
    }

    public function testCanActAcceptsOwnAsClientLikeOwn(): void
    {
        $this->assertTrue($this->invokeCanActOnZone($this->createController(), [42, 7, 'own_as_client', [], [42 => [7]], []]));
    }
}
