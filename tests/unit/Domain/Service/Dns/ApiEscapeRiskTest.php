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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\ApiEscapeRisk;

/**
 * Records PowerDNS Auth 5.1.x would rewrite when their RRset is saved through its API.
 */
class ApiEscapeRiskTest extends TestCase
{
    public function testContentWithABackslashIsAffected(): void
    {
        $this->assertTrue(ApiEscapeRisk::affects('"a\\\\b"'));
        $this->assertTrue(ApiEscapeRisk::affects('"a\\"b"'));
        $this->assertTrue(ApiEscapeRisk::affects('"a\\092b"'));
        $this->assertTrue(ApiEscapeRisk::affects('0 issue "ca\;x"'));
    }

    public function testContentWithoutABackslashIsNotAffected(): void
    {
        $this->assertFalse(ApiEscapeRisk::affects('"v=spf1 -all"'));
        $this->assertFalse(ApiEscapeRisk::affects('192.0.2.1'));
        $this->assertFalse(ApiEscapeRisk::affects(''));
    }

    public function testAffectedRrsetsAreListedOncePerNameAndType(): void
    {
        $records = [
            ['name' => 't.example.com', 'type' => 'TXT', 'content' => '"a\\\\b"'],
            ['name' => 't.example.com', 'type' => 'TXT', 'content' => '"c\\\\d"'],
            ['name' => 't.example.com', 'type' => 'TXT', 'content' => '"plain"'],
            ['name' => 'example.com', 'type' => 'CAA', 'content' => '0 issue "ca\\"x"'],
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1'],
        ];

        $this->assertSame(['t.example.com TXT', 'example.com CAA'], ApiEscapeRisk::affectedRrsets($records));
    }

    public function testNoAffectedRecordsGiveAnEmptyList(): void
    {
        $this->assertSame([], ApiEscapeRisk::affectedRrsets([['name' => 'example.com', 'type' => 'TXT', 'content' => '"x"']]));
    }
}
