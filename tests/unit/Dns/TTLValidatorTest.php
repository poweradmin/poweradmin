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

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;

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

        $result1 = $this->ttlValidator->validate("", $defaultTtl);
        $this->assertTrue($result1->isValid());
        $this->assertEquals(['ttl' => $defaultTtl], $result1->getData());

        $result2 = $this->ttlValidator->validate(null, $defaultTtl);
        $this->assertTrue($result2->isValid());
        $this->assertEquals(['ttl' => $defaultTtl], $result2->getData());
    }

    public function testValidTtlValues(): void
    {
        $defaultTtl = 3600;

        $result1 = $this->ttlValidator->validate(60, $defaultTtl);
        $this->assertTrue($result1->isValid());
        $this->assertEquals(['ttl' => 60], $result1->getData());

        $result2 = $this->ttlValidator->validate(86400, $defaultTtl);
        $this->assertTrue($result2->isValid());
        $this->assertEquals(['ttl' => 86400], $result2->getData());

        $result3 = $this->ttlValidator->validate(2147483647, $defaultTtl);
        $this->assertTrue($result3->isValid());
        $this->assertEquals(['ttl' => 2147483647], $result3->getData());

        $result4 = $this->ttlValidator->validate(0, $defaultTtl);
        $this->assertTrue($result4->isValid());
        $this->assertEquals(['ttl' => 0], $result4->getData());
    }

    public function testInvalidTtlValues(): void
    {
        $defaultTtl = 3600;

        $result1 = $this->ttlValidator->validate(-1, $defaultTtl);
        $this->assertFalse($result1->isValid());
        $this->assertNotEmpty($result1->getErrors());

        $result2 = $this->ttlValidator->validate(2147483648, $defaultTtl);
        $this->assertFalse($result2->isValid());

        $result3 = $this->ttlValidator->validate("invalid", $defaultTtl);
        $this->assertFalse($result3->isValid());
    }

    public function testTtlIsConvertedToInteger(): void
    {
        $defaultTtl = 3600;
        $result = $this->ttlValidator->validate("3600", $defaultTtl);
        $this->assertTrue($result->isValid());
        $ttl = $result->getData()['ttl'];
        $this->assertIsInt($ttl);
        $this->assertEquals(3600, $ttl);
    }
}
