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
 * A stack trace prints call arguments, including the password of a Basic auth request, so the
 * logs record where an error came from instead.
 */
class ErrorLogOriginOnlyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public static function loggingSites(): array
    {
        return [
            'entry point' => ['index.php'],
            'forgot password' => ['lib/Application/Controller/ForgotPasswordController.php'],
            'forgot username' => ['lib/Application/Controller/ForgotUsernameController.php'],
            'user provisioning' => ['lib/Application/Service/UserProvisioningService.php'],
        ];
    }

    #[DataProvider('loggingSites')]
    public function testNoTraceReachesTheLogs(string $file): void
    {
        $source = (string)file_get_contents(self::ROOT . '/' . $file);

        if ($file === 'index.php') {
            // The debug-only JSON response may still carry a trace
            $this->assertStringNotContainsString('error_log($e->getTraceAsString())', $source);
            $this->assertStringContainsString("error_log(sprintf('%s in %s:%d'", $source);
            return;
        }

        $this->assertStringNotContainsString("'trace' =>", $source);
        $this->assertStringNotContainsString('getTraceAsString', $source);
    }

    public function testDockerPhpIniDropsExceptionArguments(): void
    {
        $dockerfile = (string)file_get_contents(self::ROOT . '/Dockerfile');

        $this->assertStringContainsString('zend.exception_ignore_args = On', $dockerfile);
    }
}
