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

namespace integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * validate_input() qualifies a posted record name into its zone unless the name is
 * already inside the zone. "Inside" must mean a label boundary: a zone named
 * ample.com must not accept www.example.com as one of its own records.
 */
class DnsValidateInputZoneBoundaryTest extends TestCase
{
    private const ZONE_ID = 1;

    private Dns $dns;

    protected function setUp(): void
    {
        $db = new PDOLayer('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT)");
        $db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $db->exec("INSERT INTO domains (id, name, type) VALUES (" . self::ZONE_ID . ", 'ample.com', 'MASTER')");

        $this->dns = new Dns($db, $this->createMock(AppConfiguration::class));
    }

    public function testOutOfZoneNameIsQualifiedIntoTheZone(): void
    {
        $name = 'www.example.com';

        $this->assertTrue($this->validate($name));
        $this->assertSame('www.example.com.ample.com', $name);
    }

    public function testShortNameIsQualifiedIntoTheZone(): void
    {
        $name = 'www';

        $this->assertTrue($this->validate($name));
        $this->assertSame('www.ample.com', $name);
    }

    public function testNameInsideTheZoneIsKept(): void
    {
        $name = 'www.ample.com';

        $this->assertTrue($this->validate($name));
        $this->assertSame('www.ample.com', $name);

        $apex = 'ample.com';

        $this->assertTrue($this->validate($apex));
        $this->assertSame('ample.com', $apex);
    }

    public function testControlCharactersInContentAreRejected(): void
    {
        $name = 'txt';

        $this->assertFalse($this->validate($name, 'TXT', "\"a\nb\""));
        $this->assertFalse($this->validate($name, 'SSHFP', "1\n2\naabbcc"));
        $this->assertFalse($this->validate($name, 'A', "192.0.2.1\n"));
        $this->assertTrue($this->validate($name, 'TXT', '"a b"'));
    }

    public function testTrailingNewlineInNameIsRejected(): void
    {
        $name = "www\n";

        $this->assertFalse($this->validate($name));
    }

    private function validate(string &$name, string $type = 'A', string $content = '192.0.2.1'): bool
    {
        $prio = 0;
        $ttl = 3600;

        ob_start();
        try {
            return $this->dns->validate_input(0, self::ZONE_ID, $type, $content, $name, $prio, $ttl, 'hostmaster.ample.com', 3600);
        } finally {
            ob_end_clean();
        }
    }
}
