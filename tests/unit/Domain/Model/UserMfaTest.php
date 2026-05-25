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

use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserMfa;

#[CoversClass(UserMfa::class)]
class UserMfaTest extends TestCase
{
    private function makeMfaWithCodes(array $codes): UserMfa
    {
        return new UserMfa(
            id: 1,
            userId: 1,
            enabled: true,
            secret: null,
            recoveryCodes: json_encode($codes),
            type: UserMfa::TYPE_APP,
            lastUsedAt: null,
            createdAt: new DateTime(),
            updatedAt: null,
        );
    }

    #[Test]
    public function testValidRecoveryCodeIsAcceptedAndConsumed(): void
    {
        $mfa = $this->makeMfaWithCodes(['alpha', 'bravo', 'charlie']);

        $this->assertTrue($mfa->validateRecoveryCode('bravo'));
        $this->assertEquals(['alpha', 'charlie'], $mfa->getRecoveryCodesAsArray());
    }

    #[Test]
    public function testWrongRecoveryCodeIsRejectedAndListUnchanged(): void
    {
        $codes = ['alpha', 'bravo', 'charlie'];
        $mfa = $this->makeMfaWithCodes($codes);

        $this->assertFalse($mfa->validateRecoveryCode('delta'));
        $this->assertEquals($codes, $mfa->getRecoveryCodesAsArray());
    }

    #[Test]
    public function testSameLengthWrongRecoveryCodeIsRejected(): void
    {
        $mfa = $this->makeMfaWithCodes(['abcdef1234', 'fedcba9876']);

        // Differs only in the last char from the first stored code.
        $this->assertFalse($mfa->validateRecoveryCode('abcdef1235'));
        $this->assertEquals(['abcdef1234', 'fedcba9876'], $mfa->getRecoveryCodesAsArray());
    }

    #[Test]
    public function testRecoveryCodeCanOnlyBeUsedOnce(): void
    {
        $mfa = $this->makeMfaWithCodes(['alpha', 'bravo']);

        $this->assertTrue($mfa->validateRecoveryCode('alpha'));
        $this->assertFalse($mfa->validateRecoveryCode('alpha'));
        $this->assertEquals(['bravo'], $mfa->getRecoveryCodesAsArray());
    }
}
