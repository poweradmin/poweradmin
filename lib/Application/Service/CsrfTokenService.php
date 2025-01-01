<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Application\Service;

class CsrfTokenService {
    public const TOKEN_LENGTH = 40;

    public function generateToken(): string {
        $bytesNeeded = (int) ceil(4 * self::TOKEN_LENGTH / 3);
        $bytes = random_bytes($bytesNeeded);
        $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return substr($token, 0, self::TOKEN_LENGTH);
    }

    public function getToken(string $session_var = 'csrf_token'): string {
        return $_SESSION[$session_var] ?? '';
    }

    public function validateToken(string $token, string $session_var = 'csrf_token'): bool {
        if (!isset($_SESSION[$session_var])) {
            return false;
        }
        return hash_equals($_SESSION[$session_var], $token);
    }
}