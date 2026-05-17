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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserPreference;

#[CoversClass(UserPreference::class)]
class UserPreferenceTimezoneTest extends TestCase
{
    #[Test]
    public function testTimezoneIsValidPreferenceKey(): void
    {
        $this->assertTrue(UserPreference::isValidKey(UserPreference::KEY_TIMEZONE));
        $this->assertContains(UserPreference::KEY_TIMEZONE, UserPreference::VALID_KEYS);
    }

    #[Test]
    public function testIsValidTimezoneAcceptsKnownIdentifiers(): void
    {
        $this->assertTrue(UserPreference::isValidTimezone('UTC'));
        $this->assertTrue(UserPreference::isValidTimezone('Europe/Berlin'));
        $this->assertTrue(UserPreference::isValidTimezone('America/New_York'));
        $this->assertTrue(UserPreference::isValidTimezone('Asia/Tokyo'));
    }

    #[Test]
    public function testIsValidTimezoneRejectsBadValues(): void
    {
        $this->assertFalse(UserPreference::isValidTimezone(''));
        $this->assertFalse(UserPreference::isValidTimezone('Not/Real'));
        $this->assertFalse(UserPreference::isValidTimezone('Europe/NotACity'));
    }

    #[Test]
    public function testIsValidTimezoneRejectsAliasesSoUIRoundTrips(): void
    {
        // Strict check: only canonical IANA IDs. Aliases like GMT and US/Eastern
        // aren't in DateTimeZone::listIdentifiers(), so the cascading region/city
        // selector can't preselect them - storing them would cause silent clearing
        // on the next form save. Use isAcceptableTimezone() for the global config
        // fallback instead.
        $this->assertFalse(UserPreference::isValidTimezone('GMT'));
        $this->assertFalse(UserPreference::isValidTimezone('US/Eastern'));
    }

    #[Test]
    public function testIsAcceptableTimezoneAcceptsPhpAliases(): void
    {
        // Permissive check: accepts everything PHP's date_default_timezone_set()
        // accepts. Used for the global misc.timezone fallback so admin-set
        // aliases continue to work in MFA emails.
        $this->assertTrue(UserPreference::isAcceptableTimezone('UTC'));
        $this->assertTrue(UserPreference::isAcceptableTimezone('Europe/Berlin'));
        $this->assertTrue(UserPreference::isAcceptableTimezone('GMT'));
        $this->assertTrue(UserPreference::isAcceptableTimezone('US/Eastern'));
    }

    #[Test]
    public function testIsAcceptableTimezoneRejectsBadValues(): void
    {
        $this->assertFalse(UserPreference::isAcceptableTimezone(''));
        $this->assertFalse(UserPreference::isAcceptableTimezone('Mars/Olympus'));
    }
}
