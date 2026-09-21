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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ZoneCreateFormMessages;
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
}
