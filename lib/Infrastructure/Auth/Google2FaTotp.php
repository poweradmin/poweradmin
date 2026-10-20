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

namespace Poweradmin\Infrastructure\Auth;

use PragmaRX\Google2FA\Google2FA;
use Poweradmin\Domain\Port\TotpInterface;

/**
 * TOTP backed by pragmarx/google2fa.
 */
final class Google2FaTotp implements TotpInterface
{
    private Google2FA $google2fa;

    public function __construct(?Google2FA $google2fa = null)
    {
        $this->google2fa = $google2fa ?? new Google2FA();
    }

    public function generateSecret(int $length = 16): string
    {
        return $this->google2fa->generateSecretKey($length);
    }

    public function verify(#[\SensitiveParameter] string $secret, string $code, int $window = 1): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code, $window);
    }

    public function provisioningUri(string $issuer, string $account, #[\SensitiveParameter] string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl($issuer, $account, $secret);

        // Some authenticator apps only bind the account to the right issuer when
        // the parameter is present, and the library omits it for some inputs
        if (!str_contains($uri, 'issuer=')) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . 'issuer=' . urlencode($issuer);
        }

        return $uri;
    }
}
