<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Service\MessageService;

class TTLValidatorTest extends TestCase
{
    private TTLValidator $ttlValidator;

    protected function setUp(): void
    {
        $this->ttlValidator = new TTLValidator();
    }

    public function testDefaultTtlIsUsedWhenTtlIsEmpty(): void
    {
        $defaultTtl = 3600;
        $this->assertEquals($defaultTtl, $this->ttlValidator->isValidTTL("", $defaultTtl));
        $this->assertEquals($defaultTtl, $this->ttlValidator->isValidTTL(null, $defaultTtl));
    }

    public function testValidTtlValues(): void
    {
        $defaultTtl = 3600;
        $this->assertEquals(60, $this->ttlValidator->isValidTTL(60, $defaultTtl));
        $this->assertEquals(86400, $this->ttlValidator->isValidTTL(86400, $defaultTtl));
        $this->assertEquals(2147483647, $this->ttlValidator->isValidTTL(2147483647, $defaultTtl));
        $this->assertEquals(0, $this->ttlValidator->isValidTTL(0, $defaultTtl));
    }

    public function testInvalidTtlValues(): void
    {
        $defaultTtl = 3600;
        $this->assertFalse($this->ttlValidator->isValidTTL(-1, $defaultTtl));
        $this->assertFalse($this->ttlValidator->isValidTTL(2147483648, $defaultTtl));
        $this->assertFalse($this->ttlValidator->isValidTTL("invalid", $defaultTtl));
    }

    public function testTtlIsConvertedToInteger(): void
    {
        $defaultTtl = 3600;
        $result = $this->ttlValidator->isValidTTL("3600", $defaultTtl);
        $this->assertIsInt($result);
        $this->assertEquals(3600, $result);
    }
}
