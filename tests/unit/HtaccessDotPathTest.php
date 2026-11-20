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

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testNginxExampleChecksTheDenyRulesBeforeTheStaticAssetRule(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/nginx.conf.example');
        $this->assertNotFalse($content);
        preg_match_all('#location ~ \^(?:/poweradmin)?/\(config\|lib#', $content, $deny, PREG_OFFSET_CAPTURE);
        preg_match_all('#location ~\* \\\.\(js\|css#', $content, $static, PREG_OFFSET_CAPTURE);
        preg_match_all('#location ~ (?:\^/poweradmin)?/\\\\\. \{#', $content, $dot, PREG_OFFSET_CAPTURE);

        $this->assertCount(2, $deny[0]);
        $this->assertCount(2, $static[0]);
        $this->assertCount(2, $dot[0]);
        foreach ([0, 1] as $i) {
            $this->assertGreaterThan($deny[0][$i][1], $static[0][$i][1], 'the first matching regex location wins in nginx');
            $this->assertGreaterThan($dot[0][$i][1], $static[0][$i][1], 'the first matching regex location wins in nginx');
        }
    }

    public function testApiPreflightIsAnsweredWith204AndTheCorsHeaders(): void
    {
        $htaccess = file_get_contents(dirname(__DIR__, 2) . '/.htaccess');
        $this->assertNotFalse($htaccess);

        $preflight = strpos($htaccess, "RewriteCond %{REQUEST_METHOD} =OPTIONS\n    RewriteRule ^api(/|\$) - [R=204,L]");
        $catchAll = strpos($htaccess, 'RewriteRule ^(.*)$ index.php');

        $this->assertNotFalse($preflight, 'no rule answers the API preflight');
        $this->assertNotFalse($catchAll);
        $this->assertLessThan($catchAll, $preflight);
        $this->assertStringContainsString('Header always set Access-Control-Allow-Methods', $htaccess);
        $this->assertStringContainsString('Header always set Access-Control-Allow-Headers', $htaccess);
        $this->assertSame(4, substr_count($htaccess, ' env=CORS'));
        $this->assertSame(4, substr_count($htaccess, ' env=REDIRECT_CORS'), 'the headers must survive the internal rewrite to index.php');
    }

    /** @return array<string, array{string}> */
    public static function corsConfigFiles(): array
    {
        return [
            '.htaccess' => ['.htaccess'],
            'nginx.conf.example' => ['nginx.conf.example'],
            'caddy.conf.example' => ['caddy.conf.example'],
            'Dockerfile' => ['Dockerfile'],
        ];
    }

    #[DataProvider('corsConfigFiles')]
    public function testCorsAllowsPatch(string $file): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $this->assertNotFalse($content);

        preg_match_all('/Access-Control-Allow-Methods[^\n]*/', $content, $lines);
        $this->assertNotEmpty($lines[0]);
        foreach ($lines[0] as $line) {
            $this->assertStringContainsString('PATCH', $line, $file);
        }
    }
}
