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


namespace Poweradmin\Tests\Unit\Domain\Service\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\User\FullName;

#[CoversClass(FullName::class)]
class FullNameTest extends TestCase
{
    public function testStripsControlAndInvisibleCharacters(): void
    {
        $this->assertSame('Jane Doe', FullName::plain("Jane\r\n Doe"));
        $this->assertSame('AB', FullName::plain("A\u{202E}\u{200B}B"));
        $this->assertSame('AB', FullName::plain("A\u{FEFF}\u{2066}B"));
    }

    public function testStripsArabicLetterMarkAndTagCharacters(): void
    {
        $this->assertSame('AB', FullName::plain("A\u{061C}B"));
        $this->assertSame('AB', FullName::plain("A\u{E0041}\u{E007F}B"));
    }

    public function testKeepsJoinersSoftHyphenAndOrdinaryNames(): void
    {
        $persian = "\u{0639}\u{0644}\u{06CC}\u{200C}\u{0631}\u{0636}\u{0627}";
        $sinhala = "\u{0DC1}\u{0DCA}\u{200D}\u{0DBB}\u{0DD3}";
        $this->assertSame($persian, FullName::plain($persian));
        $this->assertSame($sinhala, FullName::plain($sinhala));
        $this->assertSame("a\u{00AD}b", FullName::plain("a\u{00AD}b"));
        $this->assertSame('Renée Müller-Łukasz 山田', FullName::plain('Renée Müller-Łukasz 山田'));
    }
}
