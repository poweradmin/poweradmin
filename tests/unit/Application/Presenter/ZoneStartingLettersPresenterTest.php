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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\ZoneStartingLettersPresenter;

#[CoversClass(ZoneStartingLettersPresenter::class)]
class ZoneStartingLettersPresenterTest extends TestCase
{
    private const LAST_LETTER = '<li class="page-item disabled"><span class="page-link" tabindex="-1">z</span></li>';
    private const SHOW_ALL = '<li class="page-item"><a class="page-link" href="/zones/forward?letter=all">Show all</a></li>';

    public function testPunycodeZonesShareOneIdnEntryAfterTheAlphabet(): void
    {
        $presenter = new ZoneStartingLettersPresenter();

        $html = $presenter->present(['a', 'idn'], false, 'a');
        $this->assertStringContainsString(self::LAST_LETTER . '<li class="page-item"><a class="page-link" href="/zones/forward?letter=idn">IDN</a></li>' . self::SHOW_ALL, $html);

        $html = $presenter->present(['idn'], false, 'idn');
        $this->assertStringContainsString(self::LAST_LETTER . '<li class="page-item active"><span class="page-link" tabindex="-1">IDN</span></li>' . self::SHOW_ALL, $html);
    }

    public function testIdnEntryIsLeftOutWithoutPunycodeZones(): void
    {
        $html = (new ZoneStartingLettersPresenter())->present(['a', 'ä', '<'], false, 'a');

        $this->assertStringContainsString(self::LAST_LETTER . self::SHOW_ALL, $html);
        $this->assertStringNotContainsString('IDN', $html);
    }
}
