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
 * The generated settings.php holds the database password and SSO secrets, so it
 * must never be created world-readable, root or not.
 */
class DockerEntrypointSettingsModeTest extends TestCase
{
    private string $entrypoint;

    protected function setUp(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($contents);
        $this->entrypoint = $contents;
    }

    public function testSettingsFileIsCreatedUnderRestrictiveUmask(): void
    {
        $umask = strpos($this->entrypoint, "\n    umask 077\n");
        $create = strpos($this->entrypoint, "\n    cat > \"\${CONFIG_FILE}\" << EOF\n");

        $this->assertNotFalse($umask);
        $this->assertNotFalse($create);
        $this->assertLessThan($create, $umask, 'umask 077 must be set before settings.php is created');
    }

    public function testSettingsFileEndsWithMode640ForRootAndNonRootAlike(): void
    {
        $this->assertMatchesRegularExpression(
            '/\n    chmod 640 "\$\{CONFIG_FILE\}" 2>\/dev\/null \|\| log /',
            $this->entrypoint,
            'chmod 640 must run at function level, not only inside the root branch'
        );
        $this->assertDoesNotMatchRegularExpression('/chmod 644 "\$\{CONFIG_FILE\}"/', $this->entrypoint);
    }
}
