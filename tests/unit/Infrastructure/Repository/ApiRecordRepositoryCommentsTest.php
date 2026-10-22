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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\ApiRecordRepository;

/**
 * The RRset comment that the API record repository hands the v2 record endpoints.
 */
class ApiRecordRepositoryCommentsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function zoneRows(): array
    {
        return [
            ['id' => 'a', 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'api_comment' => 'rrset note'],
            ['id' => 'b', 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.2', 'api_comment' => 'rrset note'],
            ['id' => 'c', 'name' => 'www.example.com', 'type' => 'TXT', 'content' => 'x', 'api_comment' => null],
            ['id' => 'd', 'name' => 'ftp.example.com', 'type' => 'A', 'content' => '192.0.2.3', 'api_comment' => ''],
        ];
    }

    public function testEveryRecordOfAnRRsetGetsTheSameCommentFromOneZoneFetch(): void
    {
        $provider = $this->createMock(DnsBackendProviderInterface::class);
        $provider->expects($this->once())->method('getRecordsByZoneId')->with(5, null)->willReturn($this->zoneRows());
        $provider->expects($this->never())->method('getRecordsByName');

        $rows = (new ApiRecordRepository($provider))->getRecordsByDomainId(5, null, true);

        $this->assertSame(['rrset note', 'rrset note', null, null], array_column($rows, 'comment'));
    }

    public function testNoCommentKeyIsAddedUnlessAsked(): void
    {
        $provider = $this->createMock(DnsBackendProviderInterface::class);
        $provider->method('getRecordsByZoneId')->willReturn($this->zoneRows());

        $rows = (new ApiRecordRepository($provider))->getRecordsByDomainId(5);

        $this->assertArrayNotHasKey('comment', $rows[0]);
    }

    public function testASingleRecordTakesTheCommentOfItsRRset(): void
    {
        $provider = $this->createMock(DnsBackendProviderInterface::class);
        $provider->expects($this->once())->method('getRecordsByName')->with(5, 'www.example.com', 'A')->willReturn(array_slice($this->zoneRows(), 0, 2));

        $this->assertSame('rrset note', (new ApiRecordRepository($provider))->getCommentForRecord('b', 5, 'www.example.com', 'A'));
    }

    public function testAnRRsetWithoutACommentGivesNull(): void
    {
        $provider = $this->createMock(DnsBackendProviderInterface::class);
        $provider->method('getRecordsByName')->willReturn([$this->zoneRows()[2]]);

        $this->assertNull((new ApiRecordRepository($provider))->getCommentForRecord('c', 5, 'www.example.com', 'TXT'));
    }
}
