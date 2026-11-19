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

/**
 * A generated admin password must not be exported: the server process inherits the
 * environment for the life of the container.
 */
class DockerEntrypointAdminEnvTest extends TestCase
{
    public function testTheAdminPasswordIsNeverExported(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($contents);

        $this->assertDoesNotMatchRegularExpression('/^\s*export\s+ADMIN_(PASSWORD|USERNAME|PASSWORD_GENERATED)=/m', $contents);
    }
}
