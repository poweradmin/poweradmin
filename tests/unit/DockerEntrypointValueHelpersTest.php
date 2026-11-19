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
 * Runs the value helpers of docker-entrypoint.sh in bash: what they print goes into the
 * generated settings.php, so a value they let through must be valid PHP there.
 */
class DockerEntrypointValueHelpersTest extends TestCase
{
    private static string $functions = '';

    public static function setUpBeforeClass(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        self::assertNotFalse($script);

        $parts = ["log() { echo \"\$*\"; }"];
        foreach (['php_sq_escape', 'php_sq', 'php_num', 'php_log_facility', 'php_str_array'] as $name) {
            self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?\n\}\n/ms', $script, $m), $name);
            $parts[] = $m[0];
        }
        self::$functions = implode("\n", $parts);
    }

    /** @return array<string, array{string, string}> */
    public static function numbers(): array
    {
        return [
            'integer' => ['300', '300'],
            'zero' => ['0', '0'],
            'negative' => ['-5', '-5'],
            'fraction' => ['0.5', '0.5'],
            'leading zero is octal in PHP' => ['0800', '30'],
            'single leading zero' => ['08', '30'],
            'negative leading zero' => ['-007', '30'],
            'unit suffix' => ['30m', '30'],
            'empty' => ['', '30'],
        ];
    }

    #[DataProvider('numbers')]
    public function testPhpNum(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->bash('php_num SETTING "$1" 30 2>/dev/null', $value));
    }

    /** @return array<string, array{string, string}> */
    public static function facilities(): array
    {
        return [
            'user' => ['LOG_USER', 'LOG_USER'],
            'local7' => ['LOG_LOCAL7', 'LOG_LOCAL7'],
            'authpriv' => ['LOG_AUTHPRIV', 'LOG_AUTHPRIV'],
            'local8 does not exist' => ['LOG_LOCAL8', 'LOG_DAEMON'],
            'severity constant' => ['LOG_ERR', 'LOG_DAEMON'],
            'made up' => ['LOG_FOO', 'LOG_DAEMON'],
            'lower case' => ['log_user', 'LOG_DAEMON'],
            'empty' => ['', 'LOG_DAEMON'],
        ];
    }

    #[DataProvider('facilities')]
    public function testPhpLogFacility(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->bash('php_log_facility SETTING "$1" LOG_DAEMON 2>/dev/null', $value));
    }

    public function testAStarInAListIsNotExpandedToFileNames(): void
    {
        $dir = sys_get_temp_dir() . '/pa-glob-' . bin2hex(random_bytes(4));
        mkdir($dir);
        touch($dir . '/a.txt');
        touch($dir . '/b.txt');

        try {
            $this->assertSame("['*', 'https://x.example']", $this->bash('php_str_array "$1"', '*, https://x.example', $dir));
        } finally {
            unlink($dir . '/a.txt');
            unlink($dir . '/b.txt');
            rmdir($dir);
        }
    }

    private function bash(string $call, string $arg, ?string $cwd = null): string
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not available');
        }

        $process = proc_open(
            [$bash, '-c', self::$functions . "\n" . $call . "\n", 'helper', $arg],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr);

        return $stdout;
    }
}
