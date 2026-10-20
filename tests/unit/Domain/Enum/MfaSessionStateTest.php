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
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\MfaSessionManager;
use Poweradmin\Infrastructure\Session\ArraySession;

class MfaSessionStateTest extends TestCase
{
    private ArraySession $session;
    private MfaSessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->manager = new MfaSessionManager($this->session);
    }

    public function testOnlyPendingBlocksAccess(): void
    {
        $this->assertTrue(MfaSessionState::PENDING->blocksAccess());
        $this->assertFalse(MfaSessionState::VERIFIED->blocksAccess());
        $this->assertFalse(MfaSessionState::NOT_REQUIRED->blocksAccess());
    }

    public function testAuthoritativeKeyWins(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_STATE, MfaSessionState::PENDING->value);
        // Legacy slots disagree; the authoritative key decides.
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, false);
        $this->session->set(SessionKeys::AUTHENTICATED, true);

        $this->assertSame(MfaSessionState::PENDING, $this->manager->currentState());
        $this->assertTrue($this->manager->isMfaRequired());
    }

    public function testSetMfaNotRequiredKeepsTheLegacySlotInStep(): void
    {
        $this->manager->setMfaNotRequired();

        $this->assertSame(MfaSessionState::NOT_REQUIRED, $this->manager->currentState());
        $this->assertFalse($this->session->get(AuthFlowSessionKeys::MFA_REQUIRED));
        $this->assertFalse($this->manager->isMfaRequired());
    }

    /**
     * A session with no MFA keys at all is the common case: users who have no
     * second factor. It must not be treated as pending.
     */
    public function testEmptySessionIsNotRequired(): void
    {
        $this->assertSame(MfaSessionState::NOT_REQUIRED, $this->manager->currentState());
        $this->assertFalse($this->manager->isMfaRequired());
    }

    public function testLegacySessionPendingIsStillRecognised(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, true);

        $this->assertSame(MfaSessionState::PENDING, $this->manager->currentState());
    }

    public function testLegacySessionVerifiedViaStatusFlag(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_STATUS, 'verified');

        $this->assertSame(MfaSessionState::VERIFIED, $this->manager->currentState());
    }

    public function testLegacySessionVerifiedViaToken(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_VERIFICATION_TOKEN, 'abc');

        $this->assertSame(MfaSessionState::VERIFIED, $this->manager->currentState());
    }

    /**
     * SQL auth re-authenticates on every request and calls setMfaRequired()
     * again; if that downgraded a verified session the user would be redirected
     * to /mfa/verify in a loop.
     */
    public function testSetMfaRequiredDoesNotDowngradeAVerifiedSession(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_STATE, MfaSessionState::VERIFIED->value);
        $this->session->set(SessionKeys::USERID, 42);

        $this->manager->setMfaRequired(42);

        $this->assertSame(MfaSessionState::VERIFIED, $this->manager->currentState());
        $this->assertFalse($this->manager->isMfaRequired());
    }

    /**
     * The guard is per-user: a second account signing in on the same browser
     * session must still be challenged, not inherit the first one's verification.
     */
    public function testADifferentUserIsStillChallengedOnAVerifiedSession(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_STATE, MfaSessionState::VERIFIED->value);
        $this->session->set(SessionKeys::USERID, 42);

        $this->manager->setMfaRequired(99);

        $this->assertSame(MfaSessionState::PENDING, $this->manager->currentState());
        $this->assertTrue($this->manager->isMfaRequired());
    }

    /**
     * Same guard, for sessions verified before MFA_STATE existed.
     */
    public function testSetMfaRequiredRespectsALegacyVerificationToken(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_VERIFICATION_TOKEN, 'abc');
        $this->session->set(SessionKeys::USERID, 42);

        $this->manager->setMfaRequired(42);

        $this->assertFalse($this->manager->isMfaRequired());
    }

    public function testGarbageInTheStateKeyFallsBackToTheLegacySlots(): void
    {
        $this->session->set(AuthFlowSessionKeys::MFA_STATE, 'nonsense');
        $this->session->set(AuthFlowSessionKeys::MFA_REQUIRED, true);

        $this->assertSame(MfaSessionState::PENDING, $this->manager->currentState());
    }
}
