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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ThemePathResolver;

class ThemePathResolverTest extends TestCase
{
    private string $appRoot;

    protected function setUp(): void
    {
        // ThemePathResolver lives at lib/Infrastructure/Configuration; the app
        // root is three levels up, matching dirname(__DIR__, 3) in the resolver.
        $this->appRoot = dirname(__DIR__, 2);
    }

    public function testRelativePathIsRootedAtAppRoot(): void
    {
        $this->assertEquals(
            $this->appRoot . '/templates',
            ThemePathResolver::toFilesystemPath('templates')
        );
    }

    public function testAbsolutePathIsReturnedUnchanged(): void
    {
        $this->assertEquals(
            '/var/www/custom-templates',
            ThemePathResolver::toFilesystemPath('/var/www/custom-templates')
        );
    }

    public function testEmptyStringFallsBackToTemplates(): void
    {
        $this->assertEquals(
            $this->appRoot . '/templates',
            ThemePathResolver::toFilesystemPath('')
        );
    }

    public function testNonStringFallsBackToTemplates(): void
    {
        $this->assertEquals(
            $this->appRoot . '/templates',
            ThemePathResolver::toFilesystemPath(null)
        );
        $this->assertEquals(
            $this->appRoot . '/templates',
            ThemePathResolver::toFilesystemPath(['templates'])
        );
    }
}
