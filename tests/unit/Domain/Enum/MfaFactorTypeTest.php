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
use Poweradmin\Domain\Enum\MfaFactorType;
use Poweradmin\Domain\Model\UserMfa;

class MfaFactorTypeTest extends TestCase
{
    /**
     * The enum backs the `user_mfa.type` column, so its values must keep
     * matching the constants that were written to existing rows.
     */
    public function testValuesMatchTheStoredColumn(): void
    {
        $this->assertSame(UserMfa::TYPE_APP, MfaFactorType::APP->value);
        $this->assertSame(UserMfa::TYPE_EMAIL, MfaFactorType::EMAIL->value);
    }

    public function testValidation(): void
    {
        $this->assertTrue(MfaFactorType::isValid('app'));
        $this->assertTrue(MfaFactorType::isValid('email'));
        $this->assertFalse(MfaFactorType::isValid('sms'));
        $this->assertFalse(MfaFactorType::isValid('APP'));
    }

    public function testConfigKeys(): void
    {
        $this->assertSame('mfa.app_enabled', MfaFactorType::APP->configKey());
        $this->assertSame('mfa.email_enabled', MfaFactorType::EMAIL->configKey());
    }
}
