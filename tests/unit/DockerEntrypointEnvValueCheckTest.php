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
 * Runs to_php_bool() and php_uint() from docker-entrypoint.sh in bash. An
 * unrecognised boolean word still reads as false but is reported, and the zone
 * limit defaults take whole non-negative integers only.
 */
class DockerEntrypointEnvValueCheckTest extends TestCase
{
    private static string $functions = '';

    public static function setUpBeforeClass(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        self::assertNotFalse($script);

        $parts = ["log() {\n    echo \"$*\"\n}\n"];
        foreach (['to_php_bool', 'php_uint'] as $name) {
            self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?\n\}\n/ms', $script, $m), $name);
            $parts[] = $m[0];
        }

        self::$functions = implode("\n", $parts);
    }

    /**
     * @return array{0: string, 1: string, 2: int} stdout, stderr, exit code
     */
    private function runBash(string $call, string ...$args): array
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not available');
        }

        $process = proc_open(
            [$bash, '-c', self::$functions . "\n" . $call, 'run', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($process)];
    }

    /** @return array<string, array{string, string, bool}> */
    public static function boolProvider(): array
    {
        return [
            'true' => ['true', 'true', false],
            'upper yes' => ['YES', 'true', false],
            'on' => ['on', 'true', false],
            'one' => ['1', 'true', false],
            'false' => ['false', 'false', false],
            'off' => ['Off', 'false', false],
            'zero' => ['0', 'false', false],
            'no' => ['no', 'false', false],
            'empty' => ['', 'false', false],
            'y is not a known word' => ['y', 'false', true],
            'enabled is not a known word' => ['enabled', 'false', true],
        ];
    }

    #[DataProvider('boolProvider')]
    public function testToPhpBool(string $input, string $expected, bool $warns): void
    {
        [$stdout, $stderr] = $this->runBash('to_php_bool "$1"', $input);

        $this->assertSame($expected, trim($stdout));
        if ($warns) {
            $this->assertStringContainsString("'" . $input . "' is not a boolean", $stderr);
        } else {
            $this->assertSame('', $stderr);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function uintProvider(): array
    {
        return [
            'zero' => ['0', true],
            'plain' => ['25', true],
            'null stays null' => ['null', true],
            'float' => ['10.0', false],
            'negative' => ['-1', false],
            'octal looking' => ['010', false],
            'words' => ['ten', false],
            'too long' => ['99999999999999999999', false],
        ];
    }

    #[DataProvider('uintProvider')]
    public function testPhpUint(string $input, bool $accepted): void
    {
        [$stdout, $stderr] = $this->runBash('php_uint PA_X "$1" null', $input);

        if ($accepted) {
            $this->assertSame($input, $stdout);
            $this->assertSame('', $stderr);
        } else {
            $this->assertSame('null', $stdout);
            $this->assertStringContainsString('PA_X=', $stderr);
        }
    }

    public function testZoneLimitDefaultsUseTheWholeNumberCheck(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');

        $this->assertStringContainsString('$(php_uint PA_DNS_DEFAULT_MAX_ZONES_PER_USER ', $script);
        $this->assertStringContainsString('$(php_uint PA_DNS_DEFAULT_MAX_ZONES_PER_GROUP ', $script);
    }
}
