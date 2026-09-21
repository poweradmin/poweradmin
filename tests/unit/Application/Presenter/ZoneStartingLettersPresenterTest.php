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
use Poweradmin\Application\Presenter\ZoneStartingLettersPresenter;

class ZoneStartingLettersPresenterTest extends TestCase
{
    private const HEADING = '<span class="text-secondary">Show zones beginning with</span><br><nav><ul class="pagination pagination-sm d-flex flex-wrap">';

    private function active(string $label): string
    {
        return '<li class="page-item active"><span class="page-link" tabindex="-1">' . $label . '</span></li>';
    }

    private function disabled(string $label): string
    {
        return '<li class="page-item disabled"><span class="page-link" tabindex="-1">' . $label . '</span></li>';
    }

    private function link(string $letter, string $label, string $prefix = '', string $rows = ''): string
    {
        return '<li class="page-item"><a class="page-link" href="' . $prefix . '/zones/forward?letter=' . $letter . $rows . '">' . $label . '</a></li>';
    }

    public function testActiveAvailableAndMissingLettersRenderDistinctItems(): void
    {
        $html = (new ZoneStartingLettersPresenter())->present(['1', 'a', 'c'], true, 'a');

        $this->assertStringStartsWith(self::HEADING . $this->link('1', '0-9') . $this->active('a') . $this->disabled('b') . $this->link('c', 'c') . $this->disabled('d'), $html);
        $this->assertStringEndsWith($this->disabled('z') . $this->link('all', 'Show all') . '</ul></nav>', $html);
    }

    public function testDigitsBucketIsActiveDisabledOrLinked(): void
    {
        $presenter = new ZoneStartingLettersPresenter();

        $this->assertStringContainsString($this->active('0-9'), $presenter->present(['1'], true, '1'));
        $this->assertStringContainsString($this->disabled('0-9'), $presenter->present(['a'], false, 'a'));
        $this->assertStringContainsString($this->link('1', '0-9'), $presenter->present(['1'], true, 'a'));
    }

    public function testShowAllIsActiveWhenNoLetterIsSelected(): void
    {
        $html = (new ZoneStartingLettersPresenter())->present(['a'], false, 'all');

        $this->assertStringEndsWith('<li class="page-item active"><span class="page-link" href="#">Show all</span></li></ul></nav>', $html);
        $this->assertStringContainsString($this->link('a', 'a'), $html);
    }

    public function testPrefixAndRowsPerPageAreAppliedToEveryLink(): void
    {
        $html = (new ZoneStartingLettersPresenter())->present(['1', 'b'], true, 'all', '/pa', 25);

        $this->assertStringContainsString($this->link('1', '0-9', '/pa', '&rows_per_page=25'), $html);
        $this->assertStringContainsString($this->link('b', 'b', '/pa', '&rows_per_page=25'), $html);
        $this->assertStringNotContainsString($this->link('all', 'Show all'), $html);
    }

    public function testNonAsciiInitialsFollowTheAlphabetEscapedAndUrlEncoded(): void
    {
        $presenter = new ZoneStartingLettersPresenter();

        $html = $presenter->present(['a', 'ä', '<'], false, 'a');
        $this->assertStringContainsString($this->disabled('z') . $this->link('%C3%A4', 'ä') . $this->link('%3C', '&lt;') . $this->link('all', 'Show all'), $html);

        $html = $presenter->present(['ä'], false, 'ä');
        $this->assertStringContainsString($this->disabled('z') . $this->active('ä') . $this->link('all', 'Show all'), $html);
    }

    public function testBlankInitialsAreIgnored(): void
    {
        $html = (new ZoneStartingLettersPresenter())->present(['', 'a'], false, 'all');

        $this->assertStringContainsString($this->disabled('z') . '<li class="page-item active">', $html);
    }
}
