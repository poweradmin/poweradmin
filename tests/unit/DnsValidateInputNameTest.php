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

namespace unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * TXT and the record types routed through the validator factory (TLSA, SMIMEA, URI,
 * SSHFP, ...) must have their name checked as a hostname, while underscore labels
 * such as _dmarc or _443._tcp keep working.
 */
class DnsValidateInputNameTest extends TestCase
{
    private const TLSA = '3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';

    private Dns $dns;

    protected function setUp(): void
    {
        $db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $db->exec("INSERT INTO domains (id, name, type) VALUES (1, 'example.com', 'MASTER')");

        $this->dns = new Dns($db, $this->createMock(AppConfiguration::class));
    }

    public function testUnderscoreNamesStillPass(): void
    {
        $this->assertTrue($this->validate('_dmarc', 'TXT', '"v=DMARC1; p=none"'));
        $this->assertTrue($this->validate('_443._tcp.www', 'TLSA', self::TLSA));
        $this->assertTrue($this->validate('_25._tcp.mail.example.com', 'TLSA', self::TLSA));
        $this->assertTrue($this->validate('_sip._tcp', 'SRV', '5 5060 sip.example.com', 10));
    }

    public function testTxtNameWithMalformedLabelIsRefused(): void
    {
        $this->assertFalse($this->validate('-bad', 'TXT', '"x"'));
        $this->assertFalse($this->validate('a..b', 'TXT', '"x"'));
        $this->assertFalse($this->validate('bad-', 'TXT', '"x"'));
    }

    public function testDefaultRoutedTypesRefuseMalformedNames(): void
    {
        $this->assertFalse($this->validate('_443._tcp.-bad', 'TLSA', self::TLSA));
        $this->assertFalse($this->validate('_443._tcp..www', 'TLSA', self::TLSA));
        $this->assertFalse($this->validate('-bad', 'SSHFP', '1 1 aabbccddeeff00112233445566778899aabbccdd'));
    }

    public function testApexAtSignAndEmptyNameMapToTheZone(): void
    {
        $this->assertSame('example.com', $this->validatedName('@', 'A', '192.0.2.1'));
        $this->assertSame('example.com', $this->validatedName('', 'A', '192.0.2.1'));
        $this->assertSame('example.com', $this->validatedName('@', 'TXT', '"v=spf1 -all"'));
        $this->assertSame('example.com', $this->validatedName('', 'TXT', '"v=spf1 -all"'));
        $this->assertSame('www.example.com', $this->validatedName('www', 'A', '192.0.2.1'));
    }

    public function testQuotedStringEscaping(): void
    {
        ob_start();
        try {
            $this->assertFalse(Dns::is_properly_quoted('"abc\\"'));
            $this->assertTrue(Dns::is_properly_quoted('"a\\\\"'));
            $this->assertTrue(Dns::is_properly_quoted('"a\\"b"'));
            $this->assertFalse(Dns::is_properly_quoted('"a"b"'));
            $this->assertFalse(Dns::is_properly_quoted('"a\\" "b"'));
        } finally {
            ob_end_clean();
        }
    }

    private function validatedName(string $name, string $type, string $content): string
    {
        $resolved = '';
        $this->assertTrue($this->validate($name, $type, $content, 0, $resolved));

        return $resolved;
    }

    private function validate(string $name, string $type, string $content, int $prio = 0, string &$resolved = ''): bool
    {
        $ttl = 3600;

        ob_start();
        try {
            $ok = $this->dns->validate_input(0, 1, $type, $content, $name, $prio, $ttl, 'hostmaster.example.com', 3600);
            $resolved = $name;

            return $ok;
        } finally {
            ob_end_clean();
        }
    }
}
