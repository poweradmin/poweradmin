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
use Poweradmin\Application\Service\ZoneMetadataFormMessages;
use Poweradmin\Domain\Service\Zone\ZoneMetadataOutcome;
use Poweradmin\Domain\Service\Zone\ZoneMetadataResult;

#[CoversClass(ZoneMetadataFormMessages::class)]
class ZoneMetadataFormMessagesTest extends TestCase
{
    public function testRefusalsNameTheKindAndWhatItNeeds(): void
    {
        $this->assertSame(
            'Invalid value for SOA-EDIT-API. Allowed values: DEFAULT, INCREASE.',
            ZoneMetadataFormMessages::errorMessage(new ZoneMetadataResult(ZoneMetadataOutcome::INVALID_VALUE, 'SOA-EDIT-API', ['DEFAULT', 'INCREASE']))
        );
        $this->assertSame(
            'Metadata kind NSEC3NARROW only takes effect together with NSEC3PARAM. Add a NSEC3PARAM row as well.',
            ZoneMetadataFormMessages::errorMessage(new ZoneMetadataResult(ZoneMetadataOutcome::COMPANION_REQUIRED, 'NSEC3NARROW', null, 'NSEC3PARAM'))
        );
        $this->assertSame(
            'Custom metadata kind MY-KIND must start with X- to be accepted by the PowerDNS API.',
            ZoneMetadataFormMessages::errorMessage(new ZoneMetadataResult(ZoneMetadataOutcome::CUSTOM_PREFIX, 'MY-KIND'))
        );
    }

    public function testAWriteFailureGetsTheGenericText(): void
    {
        $this->assertSame('Failed to update zone metadata.', ZoneMetadataFormMessages::errorMessage(new ZoneMetadataResult(ZoneMetadataOutcome::WRITE_FAILED)));
    }
}
