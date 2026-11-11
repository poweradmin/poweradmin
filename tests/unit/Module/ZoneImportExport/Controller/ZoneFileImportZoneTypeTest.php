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

namespace Poweradmin\Tests\Unit\Module\ZoneImportExport\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Module\ZoneImportExport\Controller\ZoneFileImportController;

final class ZoneFileImportZoneTypeTest extends TestCase
{
    public static function accepted(): array
    {
        return [['MASTER', 'MASTER'], ['NATIVE', 'NATIVE'], ['native', 'NATIVE']];
    }

    public static function refused(): array
    {
        return [['SLAVE'], ['PRODUCER'], ['junk'], [''], [['MASTER']], [null]];
    }

    #[Test]
    #[DataProvider('accepted')]
    public function offeredTypesPass(string $submitted, string $expected): void
    {
        $this->assertSame($expected, ZoneFileImportController::offeredZoneType($submitted));
    }

    #[Test]
    #[DataProvider('refused')]
    public function otherTypesAreRefused(mixed $submitted): void
    {
        $this->assertNull(ZoneFileImportController::offeredZoneType($submitted));
    }
}
