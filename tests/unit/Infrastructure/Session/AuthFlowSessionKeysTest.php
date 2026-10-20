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

namespace Poweradmin\Tests\Unit\Infrastructure\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use ReflectionClass;

#[CoversClass(AuthFlowSessionKeys::class)]
class AuthFlowSessionKeysTest extends TestCase
{
    /** The values are the live session contract: a rename would drop every user out of a half-done flow. */
    public function testFlowKeysKeepTheirSessionNames(): void
    {
        $this->assertSame('mfa_state', AuthFlowSessionKeys::MFA_STATE);
        $this->assertSame('mfa_required', AuthFlowSessionKeys::MFA_REQUIRED);
        $this->assertSame('mfa_status', AuthFlowSessionKeys::MFA_STATUS);
        $this->assertSame('mfa_token', AuthFlowSessionKeys::MFA_TOKEN);
        $this->assertSame('mfa_verification_token', AuthFlowSessionKeys::MFA_VERIFICATION_TOKEN);
        $this->assertSame('mfa_setup_enforced', AuthFlowSessionKeys::MFA_SETUP_ENFORCED);
        $this->assertSame('oidc_state', AuthFlowSessionKeys::OIDC_STATE);
        $this->assertSame('saml_slo_pending', AuthFlowSessionKeys::SAML_SLO_PENDING);
        $this->assertSame('password_reset_token', AuthFlowSessionKeys::PASSWORD_RESET_TOKEN);
        $this->assertSame('reset_password_token', AuthFlowSessionKeys::RESET_PASSWORD_TOKEN);
        $this->assertSame('username_recovery_token', AuthFlowSessionKeys::USERNAME_RECOVERY_TOKEN);
    }

    public function testNoKeyIsDefinedInBothCatalogues(): void
    {
        $flow = (new ReflectionClass(AuthFlowSessionKeys::class))->getConstants();
        $identity = (new ReflectionClass(SessionKeys::class))->getConstants();

        $this->assertSame([], array_intersect_key($flow, $identity));
        $this->assertSame([], array_intersect($flow, $identity));
    }
}
