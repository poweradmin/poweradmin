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

namespace Tests\Unit\Domain\Utility;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\RecordIdHelper;

class RecordIdHelperTest extends TestCase
{
    public function testNumericStringReturnsInt(): void
    {
        $this->assertSame(123, RecordIdHelper::normalizeId('123'));
    }

    public function testIntReturnsInt(): void
    {
        $this->assertSame(42, RecordIdHelper::normalizeId(42));
    }

    public function testNonNumericStringReturnsString(): void
    {
        $this->assertSame('abc-def', RecordIdHelper::normalizeId('abc-def'));
    }

    public function testUuidStringReturnsString(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $this->assertSame($uuid, RecordIdHelper::normalizeId($uuid));
    }

    public function testZeroStringReturnsInt(): void
    {
        $this->assertSame(0, RecordIdHelper::normalizeId('0'));
    }

    public function testEmptyStringReturnsString(): void
    {
        $this->assertSame('', RecordIdHelper::normalizeId(''));
    }
}
