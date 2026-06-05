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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\RecordMatchingService;

class RecordMatchingServiceTest extends TestCase
{
    private function createService(?int $forwardDomainId, array $aaaaRecords): RecordMatchingService
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainIdByName')->willReturn($forwardDomainId);

        $recordRepository = $this->createMock(RecordRepositoryInterface::class);
        $recordRepository->method('getRecordsByDomainId')->willReturn($aaaaRecords);

        return new RecordMatchingService($dnsRecord, $recordRepository);
    }

    public function testMatchesRecordsRegardlessOfCompressionForm(): void
    {
        // Same /64 written in compressed and fully-expanded forms; both must match.
        $service = $this->createService(7, [
            ['name' => 'a.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'b.example.com', 'content' => '2001:0db8:0001:0001:0000:0000:0000:0009', 'ttl' => 3600, 'prio' => 0],
        ]);

        $matches = $service->getMatchingIPv6ForwardRecords('example.com', '2001:db8:1:1');

        $this->assertCount(2, $matches);
        $this->assertEquals('2001:db8:1:1::5', $matches[0]['ip']);
        $this->assertEquals('b.example.com', $matches[1]['name']);
    }

    public function testExcludesRecordsOutsideThePrefix(): void
    {
        $service = $this->createService(7, [
            ['name' => 'in.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'out.example.com', 'content' => '2001:db8:1:2::5', 'ttl' => 3600, 'prio' => 0],
        ]);

        $matches = $service->getMatchingIPv6ForwardRecords('example.com', '2001:db8:1:1');

        $this->assertCount(1, $matches);
        $this->assertEquals('in.example.com', $matches[0]['name']);
    }

    public function testSkipsRecordsWithInvalidIpv6Content(): void
    {
        $service = $this->createService(7, [
            ['name' => 'good.example.com', 'content' => '2001:db8:1:1::5', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'bad.example.com', 'content' => 'not-an-ip', 'ttl' => 3600, 'prio' => 0],
            ['name' => 'v4.example.com', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0],
        ]);

        $matches = $service->getMatchingIPv6ForwardRecords('example.com', '2001:db8:1:1');

        $this->assertCount(1, $matches);
        $this->assertEquals('good.example.com', $matches[0]['name']);
    }

    public function testReturnsEmptyWhenForwardDomainMissing(): void
    {
        $service = $this->createService(null, []);

        $matches = $service->getMatchingIPv6ForwardRecords('missing.example.com', '2001:db8:1:1');

        $this->assertSame([], $matches);
    }
}
