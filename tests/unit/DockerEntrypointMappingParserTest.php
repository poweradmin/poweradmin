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
 * Runs parse_mapping() from docker-entrypoint.sh in bash and checks the PHP
 * array text it generates. The LDAP variables pass "dn" so that DN keys, which
 * contain commas and equal signs, can be expressed; every other caller keeps
 * the plain comma form.
 */
class DockerEntrypointMappingParserTest extends TestCase
{
    private static string $functions = '';

    public static function setUpBeforeClass(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        self::assertNotFalse($script);

        $parts = [];
        foreach (['php_sq_escape', 'php_sq'] as $name) {
            self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?\n\}\n/ms', $script, $m), $name);
            $parts[] = $m[0];
        }
        self::assertSame(1, preg_match('/^    parse_mapping\(\) \{\n.*?\n    \}\n/ms', $script, $m), 'parse_mapping');
        $parts[] = $m[0];

        self::$functions = implode("\n", $parts);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function mappingProvider(): array
    {
        return [
            'dn mode, plain = pairs' => [
                'dn', 'team1=Editors,team2=Viewers',
                "'team1' => 'Editors', 'team2' => 'Viewers'",
            ],
            'dn mode, comma colon pairs' => [
                'dn', 'grp:Tmpl,grp2:Tmpl2',
                "'grp' => 'Tmpl', 'grp2' => 'Tmpl2'",
            ],
            'dn mode, array value and trimming' => [
                'dn', 'team1=Editors|Viewers, team2 = Viewers',
                "'team1' => ['Editors', 'Viewers'], 'team2' => 'Viewers'",
            ],
            'dn mode, single DN key' => [
                'dn', 'cn=client-admins-dns,cn=groups,cn=accounts,dc=example,dc=com:Administrators',
                "'cn=client-admins-dns,cn=groups,cn=accounts,dc=example,dc=com' => 'Administrators'",
            ],
            'dn mode, two DN items, second an array' => [
                'dn', 'cn=a,ou=g,dc=x:Admins;cn=b,ou=g,dc=x:Editors|Viewers',
                "'cn=a,ou=g,dc=x' => 'Admins', 'cn=b,ou=g,dc=x' => ['Editors', 'Viewers']",
            ],
            'dn mode, escaped comma in the DN' => [
                'dn', 'cn=O\,Brien,ou=g,dc=x:Admins',
                "'cn=O\\\\,Brien,ou=g,dc=x' => 'Admins'",
            ],
            'dn mode, quote in the DN' => [
                'dn', "cn=it's,ou=g,dc=x:Admins",
                "'cn=it\\'s,ou=g,dc=x' => 'Admins'",
            ],
            'dn mode, last colon wins' => [
                'dn', 'cn=a:b,ou=g,dc=x:Admins',
                "'cn=a:b,ou=g,dc=x' => 'Admins'",
            ],
            'dn mode, trailing semicolon' => [
                'dn', 'cn=a,ou=g,dc=x:Admins;',
                "'cn=a,ou=g,dc=x' => 'Admins'",
            ],
            'dn mode, upper case DN with spaces' => [
                'dn', 'CN=DNS Admins,OU=Groups,DC=corp,DC=local:Administrator',
                "'CN=DNS Admins,OU=Groups,DC=corp,DC=local' => 'Administrator'",
            ],
            'plain mode, RDAP URL' => [
                '', 'com=https://rdap.example/',
                "'com' => 'https://rdap.example/'",
            ],
            'plain mode, OIDC pairs' => [
                '', 'admins=Administrator,users=Editor',
                "'admins' => 'Administrator', 'users' => 'Editor'",
            ],
            'plain mode ignores semicolons' => [
                '', 'a=b;c=d',
                "'a' => 'b;c=d'",
            ],
        ];
    }

    #[DataProvider('mappingProvider')]
    public function testParseMapping(string $flag, string $input, string $expected): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not available');
        }

        // bash 3.2 (macOS /bin/bash) mangles the quote escaping in php_sq_escape; the image ships bash 5
        $major = (int) shell_exec(escapeshellarg($bash) . ' -c \'echo "${BASH_VERSINFO[0]}"\'');
        if ($major < 4 && str_contains($input, "'")) {
            $this->markTestSkipped('quote escaping needs bash 4 or newer');
        }

        $script = self::$functions . "\nparse_mapping \"\$1\" \$2\n";
        $process = proc_open(
            [$bash, '-c', $script, 'parse', $input, $flag],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr);
        $this->assertSame($expected, $stdout);
    }
}
