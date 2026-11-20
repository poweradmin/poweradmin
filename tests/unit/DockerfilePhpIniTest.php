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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The image ships no php.ini, so exception arguments (a Basic auth password among them)
 * would reach a logged stack trace unless the generated ini turns them off.
 */
class DockerfilePhpIniTest extends TestCase
{
    #[Test]
    public function testGeneratedIniDropsExceptionArguments(): void
    {
        $dockerfile = (string)file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');

        $this->assertMatchesRegularExpression('/00-poweradmin\.ini/', $dockerfile);
        $this->assertStringContainsString('zend.exception_ignore_args = On', $dockerfile);
    }
}
