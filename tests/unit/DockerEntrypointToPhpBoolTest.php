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
 * to_php_bool() maps a word outside the accepted set to false; it must say so on
 * stderr instead of silently turning a typo like "y" into an open or closed gate.
 */
class DockerEntrypointToPhpBoolTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function valueProvider(): array
    {
        return [
            'true' => ['true', 'true', false],
            'upper yes' => ['YES', 'true', false],
            'on' => ['on', 'true', false],
            'digit one' => ['1', 'true', false],
            'false' => ['false', 'false', false],
            'off' => ['Off', 'false', false],
            'digit zero' => ['0', 'false', false],
            'no' => ['no', 'false', false],
            'empty' => ['', 'false', false],
            'y warns' => ['y', 'false', true],
            'enabled warns' => ['enabled', 'false', true],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testToPhpBool(string $input, string $expected, bool $warns): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not available');
        }

        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);
        $parts = [];
        foreach (['log', 'to_php_bool'] as $name) {
            $this->assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?\n\}\n/ms', $script, $m), $name);
            $parts[] = $m[0];
        }

        $process = proc_open(
            [$bash, '-c', implode("\n", $parts) . "\nto_php_bool \"\$1\"\n", 'bool', $input],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr);
        $this->assertSame($expected, trim($stdout));
        $warns ? $this->assertStringContainsString('WARNING', $stderr) : $this->assertSame('', $stderr);
    }
}
