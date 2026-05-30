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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Repository\ApiRecordRepository;

/**
 * Regression coverage for issue #1250: in API backend mode the zone record
 * listing must pin SOA/NS/apex records to the top, matching the SQL backend.
 */
class ApiRecordRepositorySortingTest extends TestCase
{
    private function backendWithRecords(string $zoneName, array $records): DnsBackendProvider
    {
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('getZoneNameById')->willReturn($zoneName);
        $provider->method('getZoneRecords')->willReturn($records);
        return $provider;
    }

    private function sampleRecords(): array
    {
        return [
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.10'],
            ['name' => 'example.com', 'type' => 'A', 'content' => '192.0.2.1'],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com'],
            ['name' => 'alpha.example.com', 'type' => 'A', 'content' => '192.0.2.20'],
            ['name' => 'example.com', 'type' => 'NS', 'content' => 'ns1.example.com'],
            ['name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 1'],
            ['name' => 'beta.example.com', 'type' => 'A', 'content' => '192.0.2.30'],
        ];
    }

    public function testApexRecordsArePinnedToTopAscending(): void
    {
        $repo = new ApiRecordRepository($this->backendWithRecords('example.com', $this->sampleRecords()));

        $records = $repo->getRecordsFromDomainId('mysql', 1, 0, 100, 'name', 'ASC');
        $order = array_map(fn($r) => $r['name'] . '/' . $r['type'], $records);

        $this->assertSame('example.com/SOA', $order[0]);
        $this->assertSame('example.com/NS', $order[1]);

        // All apex records appear before the first sub-record.
        $firstSub = null;
        foreach ($order as $i => $entry) {
            if (!str_starts_with($entry, 'example.com/')) {
                $firstSub = $i;
                break;
            }
        }
        $this->assertSame(4, $firstSub, 'All four apex records should precede sub-records');
        $this->assertSame(
            ['alpha.example.com/A', 'beta.example.com/A', 'www.example.com/A'],
            array_slice($order, 4)
        );
    }

    public function testApexRecordsStayPinnedDescending(): void
    {
        $repo = new ApiRecordRepository($this->backendWithRecords('example.com', $this->sampleRecords()));

        $records = $repo->getRecordsFromDomainId('mysql', 1, 0, 100, 'name', 'DESC');
        $order = array_map(fn($r) => $r['name'] . '/' . $r['type'], $records);

        $this->assertSame('example.com/SOA', $order[0]);
        $this->assertSame('example.com/NS', $order[1]);
        $this->assertSame(
            ['www.example.com/A', 'beta.example.com/A', 'alpha.example.com/A'],
            array_slice($order, 4)
        );
    }
}
