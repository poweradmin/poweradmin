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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\SearchResultPresenter;

#[CoversClass(SearchResultPresenter::class)]
class SearchResultPresenterTest extends TestCase
{
    public static function disabledProvider(): array
    {
        return [
            'int 0' => [0, false, 'No'],
            'int 1' => [1, true, 'Yes'],
            'string 0' => ['0', false, 'No'],
            'string 1' => ['1', true, 'Yes'],
            'postgres f' => ['f', false, 'No'],
            'postgres t' => ['t', true, 'Yes'],
            'bool false' => [false, false, 'No'],
            'bool true' => [true, true, 'Yes'],
            'null' => [null, false, 'No'],
        ];
    }

    #[DataProvider('disabledProvider')]
    public function testDisabledBecomesABoolWithATranslatedLabel(mixed $stored, bool $flag, string $label): void
    {
        $rows = SearchResultPresenter::records([['id' => 1, 'disabled' => $stored]]);

        $this->assertSame($flag, $rows[0]['disabled']);
        $this->assertSame($label, $rows[0]['disabled_label']);
    }

    public function testMissingDisabledKeyReadsAsEnabled(): void
    {
        $rows = SearchResultPresenter::records([['id' => 1]]);

        $this->assertFalse($rows[0]['disabled']);
        $this->assertSame('No', $rows[0]['disabled_label']);
    }

    public function testOtherColumnsAndRowOrderAreUntouched(): void
    {
        $rows = SearchResultPresenter::records([
            ['id' => 2, 'name' => 'b.example.com', 'disabled' => 1],
            ['id' => 1, 'name' => 'a.example.com', 'disabled' => 0],
        ]);

        $this->assertSame([2, 1], array_column($rows, 'id'));
        $this->assertSame('b.example.com', $rows[0]['name']);
        $this->assertSame([], SearchResultPresenter::records([]));
    }
}
