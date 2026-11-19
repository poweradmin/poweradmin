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
 * setup_permissions runs as root against an operator-chosen PA_CONFIG_PATH, which can sit in a
 * shared directory such as /etc or on a read-only mount.
 */
class DockerEntrypointSetupPermissionsTest extends TestCase
{
    private string $body;

    protected function setUp(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($contents);
        $start = strpos($contents, "\nsetup_permissions() {\n");
        $end = strpos($contents, "\nmain() {\n");
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->body = substr($contents, $start, $end - $start);
    }

    public function testTheConfigDirectoryIsNotChangedRecursively(): void
    {
        $block = $this->configBlock();

        $this->assertStringNotContainsString('-R', $block);
        $this->assertStringContainsString('"${CONFIG_FILE}"', $block);
        $this->assertStringContainsString('settings.defaults.php', $block);
    }

    public function testTheDirectoryItselfIsNotChowned(): void
    {
        $this->assertDoesNotMatchRegularExpression('/(chown|chmod)[^\n]*"\$\{config_dir\}"/', $this->configBlock());
        $this->assertDoesNotMatchRegularExpression('/for target in[^\n]*"\$\{config_dir\}" /', $this->configBlock());
    }

    public function testRestoringTheDefaultsFileAsRootCannotAbortTheBoot(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($contents);

        $this->assertStringNotContainsString("\n            cp /usr/local/share/settings.defaults.php \"\${defaults_file}\"\n", $contents);
        $this->assertStringNotContainsString("\n            chown www-data:www-data \"\${defaults_file}\"\n", $contents);
    }

    public function testAFailedChangeOnAReadOnlyMountOnlyWarns(): void
    {
        $block = $this->configBlock();

        $this->assertMatchesRegularExpression('/chown www-data:0 "\$\{target\}" 2>\/dev\/null \|\| log "WARNING:/', $block);
        $this->assertMatchesRegularExpression('/chmod g\+w "\$\{target\}" 2>\/dev\/null \|\| log "WARNING:/', $block);
    }

    public function testTheDataDirectoryKeepsItsRecursiveOwnership(): void
    {
        $this->assertStringContainsString('chown -R www-data:0 "${DB_DIR}"', $this->body);
    }

    private function configBlock(): string
    {
        $start = strpos($this->body, 'config_dir=$(dirname');
        $end = strpos($this->body, '# Caddy data directory');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($this->body, $start, $end - $start);
    }
}
