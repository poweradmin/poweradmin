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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\SoaFieldsPresenter;

class SoaFieldsPresenterTest extends TestCase
{
    private const SOA = 'ns1.example.com. hostmaster.example.com. 2026100501 28800 7200 604800 86400';

    public function testSevenFieldSoaRowGainsTheBreakdown(): void
    {
        $rows = SoaFieldsPresenter::decorate([['type' => 'SOA', 'content' => self::SOA]]);

        $this->assertSame('ns1.example.com.', $rows[0]['soa_fields']['primary_ns']);
        $this->assertSame('86400', $rows[0]['soa_fields']['minimum']);
    }

    public function testMalformedSoaRowFallsBackToNull(): void
    {
        $rows = SoaFieldsPresenter::decorate([['type' => 'SOA', 'content' => 'ns1.example.com. hostmaster.example.com.']]);

        $this->assertNull($rows[0]['soa_fields']);
    }

    public function testNonSoaRowIsNullEvenWithSevenFields(): void
    {
        $rows = SoaFieldsPresenter::decorate([['type' => 'TXT', 'content' => self::SOA]]);

        $this->assertNull($rows[0]['soa_fields']);
    }

    public function testKeepsOtherRowFields(): void
    {
        $rows = SoaFieldsPresenter::decorate([['id' => 7, 'type' => 'A', 'content' => '192.0.2.1']]);

        $this->assertSame(7, $rows[0]['id']);
        $this->assertSame('192.0.2.1', $rows[0]['content']);
    }
}
