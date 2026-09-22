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

namespace Poweradmin\Infrastructure\Session;

/**
 * $_SESSION keys owned by the authentication flows: MFA, the OIDC and SAML
 * logout round trips, and password and username recovery.
 *
 * The login and user identity keys stay in
 * {@see \Poweradmin\Domain\Service\Auth\SessionKeys}; the pending identity and
 * OIDC/SAML session keys stay there too because SessionPromotionService reads them.
 */
final class AuthFlowSessionKeys
{
    /** Authoritative MFA verification state; see {@see \Poweradmin\Domain\Enum\MfaSessionState}. */
    public const MFA_STATE = 'mfa_state';

    // MFA
    public const MFA_REQUIRED = 'mfa_required';
    public const MFA_STATUS = 'mfa_status';
    public const MFA_TOKEN = 'mfa_token';
    public const MFA_VERIFICATION_TOKEN = 'mfa_verification_token';
    public const MFA_SETUP_ENFORCED = 'mfa_setup_enforced';

    // OIDC / SAML logout round trips
    public const OIDC_STATE = 'oidc_state';
    public const SAML_SLO_PENDING = 'saml_slo_pending';

    // Password and username recovery
    public const PASSWORD_RESET_TOKEN = 'password_reset_token';
    public const RESET_PASSWORD_TOKEN = 'reset_password_token';
    public const USERNAME_RECOVERY_TOKEN = 'username_recovery_token';

    private function __construct()
    {
    }
}
