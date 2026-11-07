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
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class ValidatorEmailTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(false);
        $this->validator = new Validator($config);
    }

    public function testTrailingNewlineIsRejected(): void
    {
        $this->assertTrue($this->validator->isValidEmail('user@example.com'));
        $this->assertFalse($this->validator->isValidEmail("user\n@example.com"));
        $this->assertFalse($this->validator->isValidEmail("user@example.com\n"));
        $this->assertFalse($this->validator->isValidEmail("user\n"));
        $this->assertFalse(Validator::isNumber("12\n"));
        $this->assertTrue(Validator::isNumber("12"));
    }
}
