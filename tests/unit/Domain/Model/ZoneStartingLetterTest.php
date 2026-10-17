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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneStartingLetter;

#[CoversClass(ZoneStartingLetter::class)]
class ZoneStartingLetterTest extends TestCase
{
    public static function normalizeCases(): array
    {
        return [
            'letter' => ['b', 'b'],
            'upper case letter' => ['B', 'b'],
            'digits' => ['1', '1'],
            'idn' => ['idn', 'idn'],
            'all' => ['all', 'all'],
            'stale decoded initial' => ['ä', 'all'],
            'two letters' => ['ab', 'all'],
            'underscore, as in _dmarc zones' => ['_', '_'],
            'null' => [null, 'all'],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(?string $input, string $expected): void
    {
        $this->assertSame($expected, ZoneStartingLetter::normalize($input));
    }

    public static function matchCases(): array
    {
        return [
            'idn zone under idn' => ['xn--mnchen-3ya.de', 'idn', true],
            'idn zone not under x' => ['xn--mnchen-3ya.de', 'x', false],
            'ascii x zone under x' => ['xray.com', 'x', true],
            'ascii x zone not under idn' => ['xray.com', 'idn', false],
            'punycode tld stays under its letter' => ['example.xn--p1ai', 'e', true],
            'digit zone' => ['1example.com', '1', true],
            'letter is case insensitive' => ['Example.com', 'e', true],
            'all' => ['xn--mnchen-3ya.de', 'all', true],
            'empty name' => ['', 'a', false],
        ];
    }

    #[DataProvider('matchCases')]
    public function testMatches(string $name, string $letter, bool $expected): void
    {
        $this->assertSame($expected, ZoneStartingLetter::matches($name, $letter));
    }
}
