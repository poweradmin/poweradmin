<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Poweradmin\Domain\Service\RecordDisplayService;

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

        $this->assertEquals('@', $result->getDisplayName());
        $this->assertEquals('@', $result->getEditableName());

        // Test array conversion
        $arrayResult = $result->toArray();
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

        $this->assertEquals('www', $result->getDisplayName());
        $this->assertEquals('www', $result->getEditableName());
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

        $this->assertEquals('example.com', $result->getDisplayName());
        $this->assertEquals('example.com', $result->getEditableName());
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
        $this->assertEquals('@', $results[0]->getDisplayName());

        // Check regular records
        $this->assertEquals('mail', $results[1]->getDisplayName());
        $this->assertEquals('www', $results[2]->getDisplayName());
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
}
