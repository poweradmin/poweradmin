<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  Password functions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin;

class Password {

    /**
     * Generate random salt for encryption
     *
     * @param int $len salt length (default=5)
     *
     * @return string salt string
     */
    public static function salt($len = 5) {
        $valid_characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#$%^*()_-!';
        $valid_len = strlen($valid_characters) - 1;
        $salt = '';

        for ($i = 0; $i < $len; $i++) {
            $salt .= $valid_characters[mt_rand(0, $valid_len)];
        }

        return $salt;
    }

    public static function hash($password) {
        global $password_encryption, $password_encryption_cost;

        if ($password_encryption === 'md5salt') {
            return self::gen_mix_salt($password);
        } elseif ($password_encryption === 'bcrypt') {
            return password_hash($password, PASSWORD_BCRYPT, array('cost' => $password_encryption_cost));
        } else {
            return md5($password);
        }
    }

    public static function verify($password, $hash) {
        $hash_type = self::get_hash_type($hash);

        if ($hash_type === 'md5salt') {
            return self::_strsafecmp(self::mix_salt(self::extract_salt($hash), $password), $hash);
        } elseif ($hash_type === 'bcrypt') {
            return password_verify($password, $hash);
        } else {
            return self::_strsafecmp(md5($password), $hash);
        }
    }

    public static function needs_rehash($hash) {
        global $password_encryption, $password_encryption_cost;

        $hash_type = self::get_hash_type($hash);

        if ($hash_type == 'bcrypt') {
            return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $password_encryption_cost]);
        } else if ($hash_type !== $password_encryption) {
            return true;
        }
    }

    private static function get_hash_type($hash) {
        $hash_type = null;

        if (preg_match('/^[a-f0-9]{32}$/', $hash)) {
            $hash_type = 'md5';
        } else if (preg_match('/^[a-f0-9]{32}:[a-zA-Z0-9@#$%^*()_\-!]{5}$/', $hash)) {
            $hash_type = 'md5salt';
        } else if (preg_match('/^\$2y\$/', $hash)) {
            $hash_type = 'bcrypt';
        }

        return $hash_type;
    }

    /**
     * Generate random salt and salted password
     *
     * @param string $pass password
     *
     * @return string salted password
     */
    private static function gen_mix_salt($pass) {
        $salt = self::salt();
        return self::mix_salt($salt, $pass);
    }

    /**
     * Generate salted password
     *
     * @param string $salt salt
     * @param string $pass password
     *
     * @return string salted password
     */
    private static function mix_salt($salt, $pass) {
        return md5($salt . $pass) . ':' . $salt;
    }

    /**
     * Extract salt from password
     *
     * @param string $password salted password
     *
     * @return string salt
     */
    private static function extract_salt($password) {
        return substr(strstr($password, ':'), 1);
    }

    /**
     * Count the number of bytes in a string
     * @see https://github.com/ircmaxell/password_compat
     *
     * @param string $binary_string The input string
     *
     * @return int The number of bytes
     */
    private static function _strlen($binary_string) {
        if (function_exists('mb_strlen')) {
            return mb_strlen($binary_string, '8bit');
        }
        return strlen($binary_string);
    }

    /**
     *
     * @see https://github.com/ircmaxell/password_compat
     *
     * @param string $str1 The first string
     * @param string $str2 The second string
     *
     * @return bool true if they are equal, otherwise - false
     */
    private static function _strsafecmp($str1, $str2) {
        if (!is_string($str1) || !is_string($str2) || self::_strlen($str1) !== self::_strlen($str2)) {
            return false;
        }

        $status = 0;
        for ($i = 0; $i < self::_strlen($str1); $i++) {
            $status |= (ord($str1[$i]) ^ ord($str2[$i]));
        }

        return $status === 0;
    }
}
