<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

namespace Poweradmin;

class Session {

    public static function encrypt_password($password, $session_key) {
        return base64_encode(
            openssl_encrypt(
                $password,
                'aes-256-cbc',
                hash('sha256', $session_key),
                OPENSSL_RAW_DATA,
                substr(hash('sha256', hash('sha256', $session_key), TRUE), 0, 16)
            )
        );
    }

    public static function decrypt_password($password, $session_key) {
        return rtrim(
            openssl_decrypt(
                base64_decode($password),
                'aes-256-cbc',
                hash('sha256', $session_key),
                OPENSSL_RAW_DATA,
                substr(hash('sha256', hash('sha256', $session_key), TRUE), 0, 16)
            ), "\0"
        );
    }
}