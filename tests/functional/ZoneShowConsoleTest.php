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

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use TestHelpers\ConsoleSqliteTestCase;

/**
 * bin/poweradmin zone:show, end to end against the shared SQLite fixture.
 */
class ZoneShowConsoleTest extends ConsoleSqliteTestCase
{
    private const HEADER = "ID\tNAME\tTYPE\tCONTENT\tTTL\tPRIO\tDISABLED\n";
    private const SOA = 'ns1.example.com hostmaster.example.com 1 10800 3600 604800 3600';

    public function testOwnerSeesTheRecordsByZoneName(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=2', 'zone:show', 'example.com']);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame(
            self::HEADER . "100\texample.com\tSOA\t" . self::SOA . "\t3600\t0\t0\n101\twww.example.com\tA\t192.0.2.1\t3600\t0\t1\n",
            $stdout
        );
        $this->assertSame('', $stderr);
    }

    public function testUeberuserSeesAnyZoneByIdAsJson(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['zone:show', '11', '--user=1', '--format=json']);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame(
            '[{"id":102,"name":"example.net","type":"SOA","content":"ns1.example.net hostmaster.example.net 1 10800 3600 604800 3600","ttl":3600,"prio":0,"disabled":false}]' . "\n",
            $stdout
        );
    }

    public function testViewerIsRefusedAZoneTheyDoNotOwn(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=2', 'zone:show', 'example.net']);

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('may not view zone 11', $stderr);
        $this->assertStringNotContainsString('Usage:', $stderr);
    }

    public function testSystemActorIsRefused(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['zone:show', '10']);

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('--as-user', $stderr);
    }

    public function testMissingZoneExitsWithOne(): void
    {
        foreach (['99', 'nope.example'] as $zone) {
            [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=1', 'zone:show', $zone]);

            $this->assertSame(1, $exit, $zone);
            $this->assertSame('', $stdout, $zone);
            $this->assertStringContainsString(sprintf('Zone "%s" does not exist', $zone), $stderr, $zone);
        }
    }

    public function testArgumentCountIsAUsageError(): void
    {
        foreach ([['zone:show'], ['zone:show', '10', '11']] as $argv) {
            [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=1', ...$argv]);

            $this->assertSame(1, $exit);
            $this->assertSame('', $stdout);
            $this->assertStringContainsString('zone:show expects exactly one argument', $stderr);
            $this->assertStringContainsString('Usage: bin/poweradmin', $stderr);
        }
    }
}
