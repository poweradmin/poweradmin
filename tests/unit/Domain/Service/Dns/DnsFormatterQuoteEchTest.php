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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\DnsFormatter;
use TestHelpers\FakeConfiguration;

/**
 * The PowerDNS API refuses an unquoted ech value, so SVCB and HTTPS content is written with it quoted.
 */
#[CoversClass(DnsFormatter::class)]
class DnsFormatterQuoteEchTest extends TestCase
{
    public function testQuotesEchWhereverItSits(): void
    {
        $this->assertSame('1 . ech="AA==" alpn=h2', DnsFormatter::quoteEch('HTTPS', '1 . ech=AA== alpn=h2'));
        $this->assertSame('1 svc.example.com alpn=h2 ech="AAAA"', DnsFormatter::quoteEch('svcb', '1 svc.example.com alpn=h2 ech=AAAA'));
        $this->assertSame('1 . alpn=h2 ECH="AAAA"', DnsFormatter::quoteEch('HTTPS', '1 . alpn=h2 ECH=AAAA'));
    }

    public function testLeavesQuotedValuesAndOtherContentAlone(): void
    {
        $this->assertSame('1 . ech="AAAA"', DnsFormatter::quoteEch('HTTPS', '1 . ech="AAAA"'));
        $this->assertSame('1 . alpn=h2 port=443', DnsFormatter::quoteEch('HTTPS', '1 . alpn=h2 port=443'));
        $this->assertSame('1 . mandatory=ech', DnsFormatter::quoteEch('HTTPS', '1 . mandatory=ech'));
        $this->assertSame('ech=AAAA', DnsFormatter::quoteEch('TXT', 'ech=AAAA'));
    }

    public function testUnquoteEchGivesTheLegacyForm(): void
    {
        $this->assertSame('1 . ech=AA== alpn=h2', DnsFormatter::unquoteEch('HTTPS', '1 . ech="AA==" alpn=h2'));
        $this->assertNull(DnsFormatter::unquoteEch('HTTPS', '1 . alpn=h2'));
        $this->assertNull(DnsFormatter::unquoteEch('HTTPS', '1 . ech=AAAA'));
        $this->assertNull(DnsFormatter::unquoteEch('TXT', 'ech="AAAA"'));
    }

    public function testFormatContentQuotesEch(): void
    {
        $formatter = new DnsFormatter(new FakeConfiguration(['dns' => ['txt_auto_quote' => false]]));

        $this->assertSame('1 . ech="AAAA"', $formatter->formatContent('HTTPS', '1 . ech=AAAA'));
    }
}
