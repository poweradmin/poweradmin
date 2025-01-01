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

namespace Poweradmin\Domain\Service;

class PasswordEncryptionService
{
    const ALGORITHM = 'aes-256-cbc';
    const IV_LENGTH = 16;
    private string $session_key;

    public function __construct(string $session_key)
    {
        $this->session_key = $session_key;
    }

    public function encrypt(string $password): string
    {
        if (empty($password)) {
            return '';
        }

        $key = $this->computeKey();
        $iv = $this->computeIV();

        return openssl_encrypt($password, self::ALGORITHM, $key, 0, $iv) . ':' . base64_encode($iv);
    }

    public function decrypt(string $password): string
    {
        if (empty($password)) {
            return '';
        }

        $key = $this->computeKey();

        list($encryptedPassword, $iv) = explode(':', $password, 2);
        $iv = base64_decode($iv);

        return rtrim(openssl_decrypt($encryptedPassword, self::ALGORITHM, $key, 0, $iv), "\0");
    }

    private function computeKey(): string
    {
        return hash('sha256', $this->session_key);
    }

    private function computeIV(): string
    {
        return openssl_random_pseudo_bytes(self::IV_LENGTH);
    }
}
