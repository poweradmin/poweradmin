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

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * A reverse record name belongs to the longest reverse zone that equals it or ends with it on a
 * label boundary; a substring hit inside another label must not pick a zone PowerDNS never serves.
 */
class DnsRecordBestMatchingReverseZoneTest extends TestCase
{
    /**
     * @param array<int, array{name: string, id: int}> $zones in the order the query returns them (longest first)
     */
    private function dnsRecord(array $zones): DnsRecord
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls(...[...$zones, false]);

        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturn("'%.arpa'");
        $db->method('query')->willReturn($statement);

        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturn(null);

        return new DnsRecord($db, $config);
    }

    public function testSubstringInsideAnotherLabelDoesNotMatch(): void
    {
        $dnsRecord = $this->dnsRecord([
            ['name' => '2.0.192.in-addr.arpa', 'id' => 1],
            ['name' => '0.192.in-addr.arpa', 'id' => 2],
        ]);

        $this->assertSame(2, $dnsRecord->get_best_matching_zone_id_from_name('5.12.0.192.in-addr.arpa'));
    }

    public function testLongestZoneOnALabelBoundaryWins(): void
    {
        $dnsRecord = $this->dnsRecord([
            ['name' => '2.0.192.in-addr.arpa', 'id' => 1],
            ['name' => '0.192.in-addr.arpa', 'id' => 2],
        ]);

        $this->assertSame(1, $dnsRecord->get_best_matching_zone_id_from_name('5.2.0.192.IN-ADDR.ARPA'));
    }

    public function testZoneApexMatchesAndNoZoneReturnsMinusOne(): void
    {
        $zones = [['name' => '0.192.in-addr.arpa', 'id' => 2]];

        $this->assertSame(2, $this->dnsRecord($zones)->get_best_matching_zone_id_from_name('0.192.in-addr.arpa'));
        $this->assertSame(-1, $this->dnsRecord($zones)->get_best_matching_zone_id_from_name('5.1.168.192.in-addr.arpa'));
    }
}
