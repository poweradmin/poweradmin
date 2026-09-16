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

namespace Poweradmin\Domain\Service;

/**
 * Delivers MFA email verification codes; the Application layer owns the template and transport.
 */
interface MfaVerificationMailerInterface
{
    public function isMailConfigurationValid(): bool;

    /**
     * @param int $expiresAt Unix timestamp the code stops being accepted
     * @param string|null $timezone Timezone the expiry is shown in, or the server default
     */
    public function sendVerificationCode(string $email, string $code, int $expiresAt, ?string $timezone): void;
}
