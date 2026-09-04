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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\CharacterStringParser;

class CharacterStringParserTest extends TestCase
{
    public function testParsesSingleQuotedString(): void
    {
        $this->assertSame(['hello'], CharacterStringParser::parse('"hello"'));
    }

    public function testParsesMultipleQuotedStrings(): void
    {
        $this->assertSame(['one', 'two', 'three'], CharacterStringParser::parse('"one" "two" "three"'));
    }

    public function testHandlesEscapedQuoteInsideString(): void
    {
        $this->assertSame(['say \"hi\"'], CharacterStringParser::parse('"say \\"hi\\""'));
    }

    public function testReturnsNullForUnquotedContent(): void
    {
        $this->assertNull(CharacterStringParser::parse('hello'));
    }

    public function testReturnsNullForEmptyString(): void
    {
        $this->assertNull(CharacterStringParser::parse(''));
    }

    public function testReturnsNullWhenIndividualStringExceeds255Bytes(): void
    {
        $tooLong = '"' . str_repeat('x', 256) . '"';
        $this->assertNull(CharacterStringParser::parse($tooLong));
    }

    public function testReturnsNullForTrailingGarbage(): void
    {
        $this->assertNull(CharacterStringParser::parse('"good" garbage'));
    }

    public function testReturnsNullForGarbageBetweenStrings(): void
    {
        $this->assertNull(CharacterStringParser::parse('"good" garbage "ok"'));
    }

    public function testReturnsNullForMissingSeparatorBetweenStrings(): void
    {
        $this->assertNull(CharacterStringParser::parse('"one""two"'));
    }

    public function testReturnsNullForLeadingGarbage(): void
    {
        $this->assertNull(CharacterStringParser::parse('garbage "ok"'));
    }

    public function testAccepts255ByteString(): void
    {
        $exact = '"' . str_repeat('x', 255) . '"';
        $parts = CharacterStringParser::parse($exact);
        $this->assertNotNull($parts);
        $this->assertSame(255, strlen($parts[0]));
    }
}
