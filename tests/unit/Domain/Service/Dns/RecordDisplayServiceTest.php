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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordRow;
use Poweradmin\Domain\Service\Dns\RecordDisplayService;

class RecordDisplayServiceTest extends TestCase
{
    public function testApexRecordDisplaysAsAtSymbolWhenHostnameOnlyEnabled(): void
    {
        $service = new RecordDisplayService(true);
        $zoneName = 'example.com';

        $apexRecord = [
            'id' => 1,
            'name' => 'example.com',
            'type' => 'A',
            'content' => '192.168.20.251',
            'ttl' => 3600,
            'prio' => null,
            'disabled' => '0'
        ];

        $result = $service->transformRecord($apexRecord, $zoneName);

        $this->assertEquals('@', $result['display_name']);
        $this->assertEquals('@', $result['editable_name']);

        // Test array conversion
        $arrayResult = $result;
        $this->assertEquals('@', $arrayResult['display_name']);
        $this->assertEquals('@', $arrayResult['editable_name']);
    }

    public function testRegularRecordDisplaysHostnameOnlyWhenEnabled(): void
    {
        $service = new RecordDisplayService(true);
        $zoneName = 'example.com';

        $record = [
            'id' => 2,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.168.20.252',
            'ttl' => 3600,
            'prio' => null,
            'disabled' => '0'
        ];

        $result = $service->transformRecord($record, $zoneName);

        $this->assertEquals('www', $result['display_name']);
        $this->assertEquals('www', $result['editable_name']);
    }

    public function testApexRecordDisplaysFullNameWhenHostnameOnlyDisabled(): void
    {
        $service = new RecordDisplayService(false);
        $zoneName = 'example.com';

        $apexRecord = [
            'id' => 1,
            'name' => 'example.com',
            'type' => 'A',
            'content' => '192.168.20.251',
            'ttl' => 3600,
            'prio' => null,
            'disabled' => '0'
        ];

        $result = $service->transformRecord($apexRecord, $zoneName);

        $this->assertEquals('example.com', $result['display_name']);
        $this->assertEquals('example.com', $result['editable_name']);
    }

    public function testMultipleRecordsTransform(): void
    {
        $service = new RecordDisplayService(true);
        $zoneName = 'example.com';

        $records = [
            [
                'id' => 1,
                'name' => 'example.com',
                'type' => 'A',
                'content' => '192.168.20.251',
                'ttl' => 3600,
                'prio' => null,
                'disabled' => '0'
            ],
            [
                'id' => 2,
                'name' => 'mail.example.com',
                'type' => 'A',
                'content' => '192.168.40.21',
                'ttl' => 3600,
                'prio' => null,
                'disabled' => '0'
            ],
            [
                'id' => 3,
                'name' => 'www.example.com',
                'type' => 'CNAME',
                'content' => 'web.example.com',
                'ttl' => 3600,
                'prio' => null,
                'disabled' => '0'
            ]
        ];

        $results = $service->transformRecords($records, $zoneName);

        // Check apex record
        $this->assertEquals('@', $results[0]['display_name']);

        // Check regular records
        $this->assertEquals('mail', $results[1]['display_name']);
        $this->assertEquals('www', $results[2]['display_name']);
    }

    public function testRestoreFqdnFromAtSymbol(): void
    {
        $service = new RecordDisplayService(true);
        $zoneName = 'example.com';

        $restored = $service->restoreFqdn('@', $zoneName);
        $this->assertEquals('example.com', $restored);
    }

    public function testRestoreFqdnFromHostname(): void
    {
        $service = new RecordDisplayService(true);
        $zoneName = 'example.com';

        $restored = $service->restoreFqdn('www', $zoneName);
        $this->assertEquals('www.example.com', $restored);
    }

    /**
     * The zone listing hands over read models, not rows. Display used to take
     * only arrays, so the edit page fataled on every unfiltered load.
     */
    public function testAReadModelFromTheListingIsTransformedLikeARow(): void
    {
        $service = new RecordDisplayService(false);

        $record = RecordRow::fromRow([
            'id' => 5,
            'domain_id' => 1,
            'name' => 'www.example.com',
            'type' => 'AAAA',
            'content' => '2001:0db8:0000:0000:0000:0000:0000:0001',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => false,
        ]);

        $result = $service->transformRecord($record, 'example.com');

        $this->assertSame('www.example.com', $result['display_name']);
        $this->assertSame('www.example.com', $result['editable_name']);
        $this->assertSame('2001:db8::1', $result['content'], 'The AAAA content is still shortened for display');
        $this->assertSame(5, $result['id']);
        $this->assertFalse($result['is_hostname_only']);
    }

    public function testAListingOfReadModelsIsTransformed(): void
    {
        $service = new RecordDisplayService(true);

        $records = [
            RecordRow::fromRow(['id' => 1, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1']),
            RecordRow::fromRow(['id' => 2, 'name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1 hostmaster 1']),
        ];

        $result = $service->transformRecords($records, 'example.com');

        $this->assertSame('www', $result[0]['display_name']);
        $this->assertSame('@', $result[1]['display_name']);
    }
}
