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


namespace Poweradmin\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Enum\ZoneSoaHealth;

class ZoneSoaHealthTest extends TestCase
{
    /**
     * The point of the enum: a failed backend lookup used to collapse to
     * `?? false` and render as a healthy zone.
     */
    public function testNullFromTheBackendIsUnknownNotHealthy(): void
    {
        $health = ZoneSoaHealth::fromBackend(null);

        $this->assertSame(ZoneSoaHealth::UNKNOWN, $health);
        $this->assertNotSame(ZoneSoaHealth::OK, $health);
        $this->assertFalse($health->isDisabled());
        $this->assertFalse($health->isMissingSoa());
    }

    public function testHealthyZone(): void
    {
        $health = ZoneSoaHealth::fromBackend(['is_disabled' => false, 'is_missing_soa' => false]);

        $this->assertSame(ZoneSoaHealth::OK, $health);
    }

    public function testDisabledSoa(): void
    {
        $health = ZoneSoaHealth::fromBackend(['is_disabled' => true, 'is_missing_soa' => false]);

        $this->assertSame(ZoneSoaHealth::SOA_DISABLED, $health);
        $this->assertTrue($health->isDisabled());
    }

    public function testMissingSoa(): void
    {
        $health = ZoneSoaHealth::fromBackend(['is_disabled' => false, 'is_missing_soa' => true]);

        $this->assertSame(ZoneSoaHealth::SOA_MISSING, $health);
        $this->assertTrue($health->isMissingSoa());
    }

    /**
     * "Disabled" and "missing" cannot both be true of one zone, but the old
     * boolean pair could express it. Disabled wins, matching the templates.
     */
    public function testDisabledWinsOverMissing(): void
    {
        $health = ZoneSoaHealth::fromBackend(['is_disabled' => true, 'is_missing_soa' => true]);

        $this->assertSame(ZoneSoaHealth::SOA_DISABLED, $health);
    }

    public function testAbsentKeysReadAsHealthy(): void
    {
        $this->assertSame(ZoneSoaHealth::OK, ZoneSoaHealth::fromBackend([]));
    }

    /**
     * The zone lists still consume the boolean pair, so it must keep coming out
     * of the enum unchanged.
     */
    public function testZoneFieldsKeepTheLegacyKeys(): void
    {
        $this->assertSame(
            ['is_disabled' => true, 'is_missing_soa' => false, 'soa_health' => 'soa_disabled'],
            ZoneSoaHealth::SOA_DISABLED->toZoneFields()
        );
        $this->assertSame(
            ['is_disabled' => false, 'is_missing_soa' => false, 'soa_health' => 'unknown'],
            ZoneSoaHealth::UNKNOWN->toZoneFields()
        );
    }
}
