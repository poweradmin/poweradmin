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
 *
 */

namespace Poweradmin\Domain\Port;

/**
 * Time-based one-time passwords, as used by authenticator apps.
 */
interface TotpInterface
{
    /**
     * @param int $length Secret length in bytes; 16 is what authenticator apps expect
     * @return string Base32-encoded secret
     */
    public function generateSecret(int $length = 16): string;

    /**
     * @param int $window How many periods either side of now are accepted, to
     *                    tolerate a device clock that is slightly out of sync
     */
    public function verify(#[\SensitiveParameter] string $secret, string $code, int $window = 1): bool;

    /**
     * The otpauth:// URI an authenticator app scans.
     */
    public function provisioningUri(string $issuer, string $account, #[\SensitiveParameter] string $secret): string;
}
