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
use Poweradmin\Domain\Enum\MfaSessionState;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\SessionKeys;

class MfaSessionStateTest extends TestCase
{
    /**
     * setMfaRequired()/setMfaVerified() call session_write_close() then
     * session_start(), which discards $_SESSION unless a real session is open.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testOnlyPendingBlocksAccess(): void
    {
        $this->assertTrue(MfaSessionState::PENDING->blocksAccess());
        $this->assertFalse(MfaSessionState::VERIFIED->blocksAccess());
        $this->assertFalse(MfaSessionState::NOT_REQUIRED->blocksAccess());
    }

    public function testAuthoritativeKeyWins(): void
    {
        $_SESSION[SessionKeys::MFA_STATE] = MfaSessionState::PENDING->value;
        // Legacy slots disagree; the authoritative key decides.
        $_SESSION[SessionKeys::MFA_REQUIRED] = false;
        $_SESSION[SessionKeys::AUTHENTICATED] = true;

        $this->assertSame(MfaSessionState::PENDING, MfaSessionManager::currentState());
        $this->assertTrue(MfaSessionManager::isMfaRequired());
    }

    public function testSetMfaNotRequiredKeepsTheLegacySlotInStep(): void
    {
        MfaSessionManager::setMfaNotRequired();

        $this->assertSame(MfaSessionState::NOT_REQUIRED, MfaSessionManager::currentState());
        $this->assertFalse($_SESSION[SessionKeys::MFA_REQUIRED]);
        $this->assertFalse(MfaSessionManager::isMfaRequired());
    }

    /**
     * A session with no MFA keys at all is the common case: users who have no
     * second factor. It must not be treated as pending.
     */
    public function testEmptySessionIsNotRequired(): void
    {
        $this->assertSame(MfaSessionState::NOT_REQUIRED, MfaSessionManager::currentState());
        $this->assertFalse(MfaSessionManager::isMfaRequired());
    }

    public function testLegacySessionPendingIsStillRecognised(): void
    {
        $_SESSION[SessionKeys::MFA_REQUIRED] = true;

        $this->assertSame(MfaSessionState::PENDING, MfaSessionManager::currentState());
    }

    public function testLegacySessionVerifiedViaStatusFlag(): void
    {
        $_SESSION[SessionKeys::MFA_STATUS] = 'verified';

        $this->assertSame(MfaSessionState::VERIFIED, MfaSessionManager::currentState());
    }

    public function testLegacySessionVerifiedViaToken(): void
    {
        $_SESSION[SessionKeys::MFA_VERIFICATION_TOKEN] = 'abc';

        $this->assertSame(MfaSessionState::VERIFIED, MfaSessionManager::currentState());
    }

    /**
     * SQL auth re-authenticates on every request and calls setMfaRequired()
     * again; if that downgraded a verified session the user would be redirected
     * to /mfa/verify in a loop.
     */
    public function testSetMfaRequiredDoesNotDowngradeAVerifiedSession(): void
    {
        $_SESSION[SessionKeys::MFA_STATE] = MfaSessionState::VERIFIED->value;
        $_SESSION[SessionKeys::USERID] = 42;

        MfaSessionManager::setMfaRequired(42);

        $this->assertSame(MfaSessionState::VERIFIED, MfaSessionManager::currentState());
        $this->assertFalse(MfaSessionManager::isMfaRequired());
    }

    /**
     * The guard is per-user: a second account signing in on the same browser
     * session must still be challenged, not inherit the first one's verification.
     */
    public function testADifferentUserIsStillChallengedOnAVerifiedSession(): void
    {
        $_SESSION[SessionKeys::MFA_STATE] = MfaSessionState::VERIFIED->value;
        $_SESSION[SessionKeys::USERID] = 42;

        MfaSessionManager::setMfaRequired(99);

        $this->assertSame(MfaSessionState::PENDING, MfaSessionManager::currentState());
        $this->assertTrue(MfaSessionManager::isMfaRequired());
    }

    /**
     * Same guard, for sessions verified before MFA_STATE existed.
     */
    public function testSetMfaRequiredRespectsALegacyVerificationToken(): void
    {
        $_SESSION[SessionKeys::MFA_VERIFICATION_TOKEN] = 'abc';
        $_SESSION[SessionKeys::USERID] = 42;

        MfaSessionManager::setMfaRequired(42);

        $this->assertFalse(MfaSessionManager::isMfaRequired());
    }

    public function testGarbageInTheStateKeyFallsBackToTheLegacySlots(): void
    {
        $_SESSION[SessionKeys::MFA_STATE] = 'nonsense';
        $_SESSION[SessionKeys::MFA_REQUIRED] = true;

        $this->assertSame(MfaSessionState::PENDING, MfaSessionManager::currentState());
    }
}
