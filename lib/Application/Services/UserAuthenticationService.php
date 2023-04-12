<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

namespace Poweradmin\Application\Services;

use InvalidArgumentException;

class UserAuthenticationService
{

    public function salt($len = 5): string
    {
        $valid_characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#$%^*()_-!';
        $valid_len = strlen($valid_characters) - 1;
        $salt = '';

        for ($i = 0; $i < $len; $i++) {
            $salt .= $valid_characters[mt_rand(0, $valid_len)];
        }

        return $salt;
    }

    public function hash($password): string
    {
        global $password_encryption, $password_encryption_cost;

        if ($password_encryption === 'bcrypt') {
            return password_hash($password, PASSWORD_BCRYPT, array('cost' => $password_encryption_cost));
        }

        if ($password_encryption === 'argon2i') {
            return password_hash($password, PASSWORD_ARGON2I);
        }

        if ($password_encryption === 'argon2id') {
            return password_hash($password, PASSWORD_ARGON2ID);
        }

        if ($password_encryption === 'md5salt') {
            return $this->gen_mix_salt($password);
        }

        if ($password_encryption === 'md5') {
            return md5($password);
        }

        throw new InvalidArgumentException('Invalid password encryption method');
    }

    public function verify($password, $hash): bool
    {
        $hash_type = $this->determine_hash_algorithm($hash);

        if ($hash_type === 'md5salt') {
            return $this->_strsafecmp($this->mix_salt($this->extract_salt($hash), $password), $hash);
        }

        if ($hash_type === 'bcrypt' || $hash_type === 'argon2i' || $hash_type === 'argon2id') {
            return password_verify($password, $hash);
        }

        if ($hash_type === 'md5') {
            return $this->_strsafecmp(md5($password), $hash);
        }

        throw new InvalidArgumentException('Unable to determine hash algorithm');
    }

    public function needs_rehash($hash): bool
    {
        global $password_encryption, $password_encryption_cost;

        $hash_type = $this->determine_hash_algorithm($hash);
        if ($hash_type == "unknown") {
            throw new InvalidArgumentException('Unable to determine hash algorithm');
        }

        if ($hash_type !== $password_encryption) {
            return true;
        }

        if ($hash_type == 'bcrypt') {
            return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $password_encryption_cost]);
        }

        if ($hash_type == 'argon2i') {
            return password_needs_rehash($hash, PASSWORD_ARGON2I);
        }

        if ($hash_type == 'argon2id') {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID);
        }

        return false;
    }

    public function determine_hash_algorithm($hash): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return 'md5';
        }

        if (preg_match('/^[a-f0-9]{32}:[a-zA-Z0-9@#$%^*()_\-!]{5}$/', $hash)) {
            return 'md5salt';
        }

        $hash_info = password_get_info($hash);
        if ($hash_info['algo'] != null) {
            return $hash_info['algoName'];
        }

        // Throw an exception if the hash type cannot be determined
        throw new InvalidArgumentException('Unable to determine hash algorithm');
    }

    public function gen_mix_salt($pass): string
    {
        $salt = $this->salt();
        return $this->mix_salt($salt, $pass);
    }

    public function mix_salt($salt, $pass): string
    {
        return md5($salt . $pass) . ':' . $salt;
    }

    public function extract_salt($password): string
    {
        return substr(strstr($password, ':'), 1);
    }

    private function _strsafecmp($str1, $str2): bool
    {
        if (!is_string($str1) || !is_string($str2) || strlen($str1) !== strlen($str2)) {
            return false;
        }

        $status = 0;
        for ($i = 0; $i < strlen($str1); $i++) {
            $status |= (ord($str1[$i]) ^ ord($str2[$i]));
        }

        return $status === 0;
    }
}
