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

namespace Poweradmin\Tests\Unit\Domain\Utility;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Utility\SoaContent;

class SoaContentTest extends TestCase
{
    public function testParsesSevenFieldsIntoNamedParts(): void
    {
        $this->assertSame(
            [
                'primary_ns' => 'ns1.example.com.',
                'hostmaster' => 'hostmaster.example.com.',
                'serial' => '2026100501',
                'refresh' => '28800',
                'retry' => '7200',
                'expire' => '604800',
                'minimum' => '86400',
            ],
            SoaContent::parse('ns1.example.com. hostmaster.example.com. 2026100501 28800 7200 604800 86400')
        );
    }

    public function testFewerThanSevenFieldsReturnsNull(): void
    {
        $this->assertNull(SoaContent::parse('ns1.example.com. hostmaster.example.com. 1'));
    }

    public function testMoreThanSevenFieldsReturnsNull(): void
    {
        $this->assertNull(SoaContent::parse('ns1.example.com. hm.example.com. 1 2 3 4 5 6'));
    }

    public function testExtraWhitespaceIsIgnored(): void
    {
        $fields = SoaContent::parse("  ns1.example.com.   hm.example.com.\t1  2 3\n4   5 ");

        $this->assertNotNull($fields);
        $this->assertSame('ns1.example.com.', $fields['primary_ns']);
        $this->assertSame('5', $fields['minimum']);
    }

    public function testEmptyContentReturnsNull(): void
    {
        $this->assertNull(SoaContent::parse(''));
        $this->assertNull(SoaContent::parse("   \t "));
    }
}
