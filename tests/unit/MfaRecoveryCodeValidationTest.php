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

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserMfa;

/**
 * Tests for MFA recovery code validation
 *
 * Covers fix(auth): correct recovery code input validation length, closes #784
 */
class MfaRecoveryCodeValidationTest extends TestCase
{
    private function createUserMfa(int $userId = 1, string $type = 'app'): UserMfa
    {
        return UserMfa::create($userId, $type);
    }

    /**
     * Test that valid recovery codes are accepted
     */
    public function testValidRecoveryCodeIsAccepted(): void
    {
        $userMfa = $this->createUserMfa();
        $codes = $userMfa->generateRecoveryCodes(5, 5); // 5 codes, 5 bytes = 10 hex chars

        $this->assertNotEmpty($codes);
        $this->assertCount(5, $codes);

        // First code should be valid
        $firstCode = $codes[0];
        $this->assertTrue($userMfa->validateRecoveryCode($firstCode));
    }

    /**
     * Test that invalid recovery codes are rejected
     */
    public function testInvalidRecoveryCodeIsRejected(): void
    {
        $userMfa = $this->createUserMfa();
        $userMfa->generateRecoveryCodes(5, 5);

        // Random invalid code should be rejected
        $this->assertFalse($userMfa->validateRecoveryCode('invalidcode123'));
        $this->assertFalse($userMfa->validateRecoveryCode(''));
        $this->assertFalse($userMfa->validateRecoveryCode('abc'));
    }

    /**
     * Test that used recovery codes are removed from the list
     */
    public function testUsedRecoveryCodeIsRemoved(): void
    {
        $userMfa = $this->createUserMfa();
        $codes = $userMfa->generateRecoveryCodes(5, 5);

        $firstCode = $codes[0];

        // Use the code
        $this->assertTrue($userMfa->validateRecoveryCode($firstCode));

        // Same code should no longer be valid
        $this->assertFalse($userMfa->validateRecoveryCode($firstCode));

        // Should have 4 codes left
        $remainingCodes = $userMfa->getRecoveryCodesAsArray();
        $this->assertCount(4, $remainingCodes);
    }

    /**
     * Test that recovery codes have the correct length
     */
    public function testRecoveryCodeLength(): void
    {
        $userMfa = $this->createUserMfa();

        // Generate codes with 5 bytes = 10 hex characters
        $codes = $userMfa->generateRecoveryCodes(3, 5);

        foreach ($codes as $code) {
            $this->assertEquals(10, strlen($code), "Recovery code should be 10 characters (5 bytes hex encoded)");
        }

        // Generate codes with 8 bytes = 16 hex characters
        $codes = $userMfa->generateRecoveryCodes(3, 8);

        foreach ($codes as $code) {
            $this->assertEquals(16, strlen($code), "Recovery code should be 16 characters (8 bytes hex encoded)");
        }
    }

    /**
     * Test that recovery codes are hex encoded
     */
    public function testRecoveryCodesAreHexEncoded(): void
    {
        $userMfa = $this->createUserMfa();
        $codes = $userMfa->generateRecoveryCodes(5, 5);

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $code, "Recovery code should be hex encoded");
        }
    }

    /**
     * Test that all recovery codes can be used
     */
    public function testAllRecoveryCodesCanBeUsed(): void
    {
        $userMfa = $this->createUserMfa();
        $codes = $userMfa->generateRecoveryCodes(5, 5);

        // Use all codes
        foreach ($codes as $code) {
            $this->assertTrue($userMfa->validateRecoveryCode($code), "Each recovery code should be usable once");
        }

        // All codes should be used
        $remainingCodes = $userMfa->getRecoveryCodesAsArray();
        $this->assertCount(0, $remainingCodes);
    }

    /**
     * Test that regenerating codes replaces old codes
     */
    public function testRegeneratingCodesReplacesOldCodes(): void
    {
        $userMfa = $this->createUserMfa();

        // Generate initial codes
        $oldCodes = $userMfa->generateRecoveryCodes(5, 5);

        // Regenerate codes
        $newCodes = $userMfa->generateRecoveryCodes(5, 5);

        // Old codes should no longer work
        foreach ($oldCodes as $code) {
            $this->assertFalse($userMfa->validateRecoveryCode($code), "Old recovery codes should not work after regeneration");
        }

        // New codes should work
        $this->assertTrue($userMfa->validateRecoveryCode($newCodes[0]));
    }

    /**
     * Test empty recovery codes array
     */
    public function testEmptyRecoveryCodesArray(): void
    {
        $userMfa = $this->createUserMfa();

        // Without generating codes, validation should fail
        $this->assertFalse($userMfa->validateRecoveryCode('anycode'));
        $this->assertEmpty($userMfa->getRecoveryCodesAsArray());
    }
}
