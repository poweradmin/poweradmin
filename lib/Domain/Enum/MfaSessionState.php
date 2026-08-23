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


namespace Poweradmin\Domain\Enum;

/**
 * Where the current session sits in second-factor verification.
 *
 * One logical state used to be spread across four session slots
 * (mfa_status, mfa_required, authenticated, mfa_verification_token) that five
 * different writers updated in different combinations, so
 * MfaSessionManager::isMfaRequired() had to reconcile them across four branches.
 *
 * The legacy slots are still written for sessions and callers that read them
 * directly; this is the value that decides.
 */
enum MfaSessionState: string
{
    /** No second factor applies - either the user has none or MFA is off. */
    case NOT_REQUIRED = 'not_required';

    /** Credentials accepted, second factor still outstanding. */
    case PENDING = 'pending';

    /** Second factor passed; the session is fully authenticated. */
    case VERIFIED = 'verified';

    public static function tryFromSession(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Whether the user still has to present a second factor.
     */
    public function blocksAccess(): bool
    {
        return $this === self::PENDING;
    }
}
