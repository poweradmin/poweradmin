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
 * The existing-file rule serves any real file, so the refusal of dot paths has to come first.
 */
class HtaccessDotPathTest extends TestCase
{
    public function testDotPathsAreRefusedBeforeExistingFilesAreServed(): void
    {
        $htaccess = file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
        $this->assertNotFalse($htaccess);

        $deny = strpos($htaccess, 'RewriteRule (^|/)\\.(?!well-known/) - [F,L]');
        $allow = strpos($htaccess, 'RewriteRule ^vendor/twbs/');
        $serve = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f');

        $this->assertNotFalse($deny, 'no rule refuses dot path segments');
        $this->assertNotFalse($serve);
        $this->assertNotFalse($allow);
        $this->assertLessThan($allow, $deny);
        $this->assertLessThan($serve, $deny);
    }

    public function testTheDotRuleMatchesNestedAndRootDotSegments(): void
    {
        $rule = '#(^|/)\.(?!well-known/)#';
        $this->assertSame(1, preg_match($rule, '.git/config'));
        $this->assertSame(1, preg_match($rule, 'sub/.env'));
        $this->assertSame(0, preg_match($rule, 'assets/app.min.js'));
        $this->assertSame(0, preg_match($rule, '.well-known/acme-challenge/token'));
    }

    public function testOnlyTheBootstrapAssetDirectoriesAreAllowedThroughTheVendorDeny(): void
    {
        $root = dirname(__DIR__, 2);
        $htaccess = file_get_contents($root . '/.htaccess');
        $nginx = file_get_contents($root . '/nginx.conf.example');
        $this->assertNotFalse($htaccess);
        $this->assertNotFalse($nginx);

        $this->assertStringContainsString('RewriteRule ^vendor/twbs/(bootstrap/dist|bootstrap-icons/font)/ - [L]', $htaccess);
        $this->assertStringNotContainsString('(bootstrap|bootstrap-icons)/', $htaccess);
        $this->assertStringNotContainsString('(bootstrap|bootstrap-icons)/', $nginx);
        $this->assertSame(2, substr_count($nginx, 'twbs/(bootstrap/dist|bootstrap-icons/font)/'));
    }
}
