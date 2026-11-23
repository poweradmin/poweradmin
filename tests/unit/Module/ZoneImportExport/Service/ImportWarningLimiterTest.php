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

namespace Poweradmin\Tests\Unit\Module\ZoneImportExport\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Module\ZoneImportExport\Service\BindZoneFileParser;
use Poweradmin\Module\ZoneImportExport\Service\ImportWarningLimiter;

class ImportWarningLimiterTest extends TestCase
{
    public function testShortListIsKeptAsIs(): void
    {
        $warnings = array_map(static fn(int $i): string => "w$i", range(1, 100));

        $this->assertSame($warnings, ImportWarningLimiter::cap($warnings, '%d more'));
    }

    public function testLongListKeepsTheFirstHundredAndAnExactCount(): void
    {
        $warnings = array_map(static fn(int $i): string => "w$i", range(1, 12345));

        $capped = ImportWarningLimiter::cap($warnings, '... and %d more warnings');

        $this->assertCount(101, $capped);
        $this->assertSame('w1', $capped[0]);
        $this->assertSame('w100', $capped[99]);
        $this->assertSame('... and 12245 more warnings', $capped[100]);
    }

    public function testParserWarningsForAHugeFileStayBounded(): void
    {
        $content = "example.com. IN A 192.0.2.1\n" . str_repeat("\$X\n", 20000);
        $parsed = (new BindZoneFileParser())->parse($content);

        $this->assertCount(20000, $parsed->getWarnings());
        $capped = ImportWarningLimiter::cap($parsed->getWarnings(), '... and %d more warnings');
        $this->assertCount(101, $capped);
        $this->assertSame('... and 19900 more warnings', $capped[100]);
    }
}
