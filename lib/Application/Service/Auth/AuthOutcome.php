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

namespace Poweradmin\Application\Service\Auth;

/**
 * Result of an authenticator run: the decision, the login message on failure, and the
 * application path the caller should redirect to when the request was a login post.
 */
final readonly class AuthOutcome
{
    private function __construct(
        public AuthOutcomeStatus $status,
        public ?string $redirectPath = null,
        public string $message = '',
        public bool $endSession = false,
    ) {
    }

    /**
     * The session is authenticated. $redirectPath is set when a login just completed.
     */
    public static function success(?string $redirectPath = null): self
    {
        return new self(AuthOutcomeStatus::Success, $redirectPath);
    }

    /**
     * The first factor was accepted and the second is still owed. $redirectPath is
     * set when the credentials were just posted.
     */
    public static function mfaRequired(?string $redirectPath = null): self
    {
        return new self(AuthOutcomeStatus::MfaRequired, $redirectPath);
    }

    /**
     * The request is not authenticated; $message goes to the login page. $endSession
     * asks the caller to clear the session as well, not only to flash the message.
     */
    public static function failure(string $message, bool $endSession = false): self
    {
        return new self(AuthOutcomeStatus::Failure, null, $message, $endSession);
    }

    public function isFailure(): bool
    {
        return $this->status === AuthOutcomeStatus::Failure;
    }
}
