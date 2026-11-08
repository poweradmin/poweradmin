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
        foreach ($mfa->getRecoveryCodesAsArray() as $i => $stored) {
            $this->assertStringStartsWith('$2y$', $stored);
            $this->assertStringNotContainsString('sha256:', $stored);
            $this->assertTrue(password_verify($codes[$i], $stored));
        }
    }

    #[Test]
    public function testNewCodesAreTenCharactersAndSalted(): void
    {
        $mfa = $this->makeMfaWithCodes([]);
        $codes = $mfa->generateRecoveryCodes(2);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $codes[0]);
        $this->assertNotSame($mfa->getRecoveryCodesAsArray()[0], password_hash($codes[0], PASSWORD_BCRYPT, ['cost' => 9]));
    }

    #[Test]
    public function testSha256EntryFromTheEarlierFormatVerifiesOnceAndStaysUntilUsed(): void
    {
        $sha = fn(string $c) => 'sha256:' . hash('sha256', $c);
        $mfa = $this->makeMfaWithCodes([$sha('aaaaaaaaaa'), $sha('bbbbbbbbbb')]);

        $this->assertFalse($mfa->validateRecoveryCode('cccccccccc'));
        $this->assertSame([$sha('aaaaaaaaaa'), $sha('bbbbbbbbbb')], $mfa->getRecoveryCodesAsArray());
        $this->assertTrue($mfa->validateRecoveryCode(' BBBBBBBBBB '));
        $this->assertFalse($mfa->validateRecoveryCode('bbbbbbbbbb'));
        $this->assertSame([$sha('aaaaaaaaaa')], $mfa->getRecoveryCodesAsArray());
    }

    #[Test]
    public function testAllThreeStoredFormsVerifyOnceAndAreConsumed(): void
    {
        $bcrypt = password_hash('cccccccccc', PASSWORD_BCRYPT, ['cost' => 4]);
        $mfa = $this->makeMfaWithCodes(['aaaaaaaaaa', 'sha256:' . hash('sha256', 'bbbbbbbbbb'), $bcrypt]);

        // The plaintext entry goes first, before any other attempt rewrites it as bcrypt
        foreach (['aaaaaaaaaa', 'cccccccccc', 'bbbbbbbbbb'] as $code) {
            $this->assertTrue($mfa->validateRecoveryCode($code), $code);
            $this->assertFalse($mfa->validateRecoveryCode($code), $code);
        }
        $this->assertSame([], $mfa->getRecoveryCodesAsArray());
    }

    #[Test]
    public function testADuplicatePlaintextEntryIsKeptAsBcrypt(): void
    {
        $mfa = $this->makeMfaWithCodes(['aaaaaaaaaa', 'aaaaaaaaaa']);

        $this->assertTrue($mfa->validateRecoveryCode('aaaaaaaaaa'));

        $left = $mfa->getRecoveryCodesAsArray();
        $this->assertCount(1, $left);
        $this->assertStringStartsWith('$2y$', $left[0]);
    }

    #[Test]
    public function testPlaintextEntriesBecomeBcryptOnAFailedAttempt(): void
    {
        $mfa = $this->makeMfaWithCodes(['abcdef1234', 'fedcba9876']);

        $this->assertFalse($mfa->validateRecoveryCode('wrongwrong'));

        foreach ($mfa->getRecoveryCodesAsArray() as $stored) {
            $this->assertStringStartsWith('$2y$', $stored);
        }
        $this->assertTrue($mfa->validateRecoveryCode('FEDCBA9876'));
        $this->assertCount(1, $mfa->getRecoveryCodesAsArray());
    }

    #[Test]
    public function testACodeWithANulByteDoesNotMatchItsPrefix(): void
    {
        $mfa = $this->makeMfaWithCodes([]);
        $codes = $mfa->generateRecoveryCodes(1);

        $this->assertFalse($mfa->validateRecoveryCode($codes[0] . "\0junk"));
        $this->assertTrue($mfa->validateRecoveryCode($codes[0]));
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
