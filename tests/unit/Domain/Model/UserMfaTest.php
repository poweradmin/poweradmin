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
        $mfa = $this->makeMfaWithCodes([]);
        $codes = $mfa->generateRecoveryCodes(3);

        $this->assertTrue($mfa->validateRecoveryCode($codes[1]));
        $this->assertCount(2, $mfa->getRecoveryCodesAsArray());
        $this->assertFalse($mfa->validateRecoveryCode($codes[1]));
        $this->assertTrue($mfa->validateRecoveryCode($codes[0]));
    }

    #[Test]
    public function testStoredRecoveryCodesAreHashesNotTheCodesShown(): void
    {
        $mfa = $this->makeMfaWithCodes([]);
        $codes = $mfa->generateRecoveryCodes(2);

        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, (string)$mfa->getRecoveryCodes());
        }
        $this->assertSame('sha256:' . hash('sha256', $codes[0]), $mfa->getRecoveryCodesAsArray()[0]);
    }

    #[Test]
    public function testWrongRecoveryCodeIsRejectedAndListUnchanged(): void
    {
        $mfa = $this->makeMfaWithCodes([]);
        $mfa->generateRecoveryCodes(3);
        $before = $mfa->getRecoveryCodes();

        $this->assertFalse($mfa->validateRecoveryCode('delta'));
        $this->assertSame($before, $mfa->getRecoveryCodes());
    }

    #[Test]
    public function testRecoveryCodeIsMatchedIgnoringCaseAndSurroundingSpace(): void
    {
        $mfa = $this->makeMfaWithCodes([]);
        $codes = $mfa->generateRecoveryCodes(1);

        $this->assertTrue($mfa->validateRecoveryCode(' ' . strtoupper($codes[0]) . ' '));
    }

    #[Test]
    public function testLegacyPlaintextCodeStillWorksOnceAndIsConsumed(): void
    {
        $mfa = $this->makeMfaWithCodes(['alpha', 'bravo', 'charlie']);

        $this->assertTrue($mfa->validateRecoveryCode('bravo'));
        $this->assertFalse($mfa->validateRecoveryCode('bravo'));
        $this->assertTrue($mfa->validateRecoveryCode('alpha'));
    }

    #[Test]
    public function testLegacyPlaintextCodesAreHashedEvenWhenTheGuessIsWrong(): void
    {
        $mfa = $this->makeMfaWithCodes(['abcdef1234', 'fedcba9876']);

        $this->assertFalse($mfa->validateRecoveryCode('abcdef1235'));
        $this->assertStringNotContainsString('abcdef1234', (string)$mfa->getRecoveryCodes());
        $this->assertCount(2, $mfa->getRecoveryCodesAsArray());
        $this->assertTrue($mfa->validateRecoveryCode('abcdef1234'));
    }

    #[Test]
    public function testALegacyCodeAsLongAsAHashIsNotMistakenForOne(): void
    {
        $code = str_repeat('a', 64);
        $mfa = $this->makeMfaWithCodes([$code]);

        $this->assertTrue($mfa->validateRecoveryCode($code));
    }

    #[Test]
    public function testDisableDropsSecretRecoveryCodesAndPendingCode(): void
    {
        $mfa = UserMfa::create(7, true, 'JBSWY3DPEHPK3PXP', json_encode(['alpha', 'bravo']), UserMfa::TYPE_EMAIL, '{"code":"123456"}');

        $mfa->disable();

        $this->assertFalse($mfa->isEnabled());
        $this->assertNull($mfa->getSecret());
        $this->assertNull($mfa->getRecoveryCodes());
        $this->assertNull($mfa->getVerificationData());
        $this->assertSame(UserMfa::TYPE_APP, $mfa->getType());
        $this->assertFalse($mfa->validateRecoveryCode('alpha'));
    }
}
