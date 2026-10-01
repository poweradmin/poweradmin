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

namespace Poweradmin\Tests\Unit\Application\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Zone\ZoneCreateFormMessages;
use Poweradmin\Domain\Service\Zone\ShadowedRecords;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;

#[CoversClass(ZoneCreateFormMessages::class)]
class ZoneCreateFormMessagesTest extends TestCase
{
    public function testValidationCodesGetTheFormWording(): void
    {
        $this->assertSame('Invalid hostname.', ZoneCreateFormMessages::errorMessage(['message' => 'Invalid domain name', 'code' => ZoneManagementService::ERR_INVALID_NAME]));
        $this->assertSame('There is already a zone with this name.', ZoneCreateFormMessages::errorMessage(['message' => 'Domain already exists', 'code' => ZoneManagementService::ERR_EXISTS]));
        $this->assertSame('This is not a valid IPv4 or IPv6 address.', ZoneCreateFormMessages::errorMessage(['message' => 'x', 'code' => ZoneManagementService::ERR_INVALID_MASTER]));
        $this->assertSame('Invalid or unexpected input given.', ZoneCreateFormMessages::errorMessage(['message' => 'x', 'code' => ZoneManagementService::ERR_TEMPLATE_FORBIDDEN]));
    }

    public function testAZoneWriteRefusalKeepsItsOwnReason(): void
    {
        $result = ['message' => 'You do not have the permission to add a master zone.', 'code' => ZoneManagementService::ERR_ZONE_WRITE];

        $this->assertSame('You do not have the permission to add a master zone.', ZoneCreateFormMessages::errorMessage($result));
    }

    public function testTheShadowWarningListsTheFirstRecordsAndCountsTheRest(): void
    {
        $one = new ShadowedRecords(1, 'example.com', [['name' => 'www.sub.example.com', 'type' => 'A']]);
        $this->assertSame(
            '1 record in zone example.com is now hidden by zone sub.example.com: www.sub.example.com (A). Make sure sub.example.com serves it, then delete it from example.com.',
            ZoneCreateFormMessages::shadowedRecords($one, 'sub.example.com')
        );

        $records = [];
        for ($i = 1; $i <= 7; $i++) {
            $records[] = ['name' => "h$i.sub.example.com", 'type' => 'A'];
        }
        $message = ZoneCreateFormMessages::shadowedRecords(new ShadowedRecords(1, 'example.com', $records), 'sub.example.com');
        $this->assertStringStartsWith('7 records in zone example.com are now hidden', $message);
        $this->assertStringContainsString('h5.sub.example.com (A), 2 more.', $message);
        $this->assertStringNotContainsString('h6.sub', $message);

        $idn = new ShadowedRecords(1, 'xn--bcher-kva.example', [['name' => 'www.sub.xn--bcher-kva.example', 'type' => 'A']]);
        $this->assertStringStartsWith(
            '1 record in zone bücher.example is now hidden by zone sub.bücher.example: www.sub.bücher.example (A).',
            ZoneCreateFormMessages::shadowedRecords($idn, 'sub.xn--bcher-kva.example')
        );
    }
}
