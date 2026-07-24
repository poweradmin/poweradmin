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

namespace Poweradmin\Tests\Unit\Infrastructure\Web;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Web\PermissionTwigExtension;
use Twig\TwigFunction;

class PermissionTwigExtensionTest extends TestCase
{
    public function testCanDelegatesToChecker(): void
    {
        $ext = new PermissionTwigExtension(fn(string $permission): bool => $permission === 'search');

        $this->assertTrue($ext->can('search'));
        $this->assertFalse($ext->can('user_add_new'));
    }

    public function testCheckerReceivesPermissionName(): void
    {
        $received = [];
        $ext = new PermissionTwigExtension(function (string $permission) use (&$received): bool {
            $received[] = $permission;
            return true;
        });

        $ext->can('zone_master_add');

        $this->assertSame(['zone_master_add'], $received);
    }

    public function testCheckerNotInvokedOnConstructionOrGetFunctions(): void
    {
        $calls = 0;
        $ext = new PermissionTwigExtension(function (string $permission) use (&$calls): bool {
            $calls++;
            return true;
        });

        $ext->getFunctions();

        $this->assertSame(0, $calls);
    }

    public function testGetFunctionsExposesSingleCanFunction(): void
    {
        $ext = new PermissionTwigExtension(fn(string $permission): bool => true);

        $functions = $ext->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertSame('can', $functions[0]->getName());
    }
}
