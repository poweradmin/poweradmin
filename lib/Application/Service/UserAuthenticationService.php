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

use InvalidArgumentException;

/**
 * Class UserAuthenticationService
 *
 * Provides various methods for managing password hashing and verification.
 */
class UserAuthenticationService
{
    private string $passwordEncryption;
    private int $passwordEncryptionCost;

    public function __construct(string $passwordEncryption = 'bcrypt', int $passwordEncryptionCost = 10)
    {
        $this->passwordEncryption = $passwordEncryption;
        $this->passwordEncryptionCost = $passwordEncryptionCost;
    }

    /**
     * Generate a random salt string.
     *
     * @param int $len The length of the salt string (default is 5).
     * @return string The generated salt string.
     */
    public function generateSalt(int $len = 5): string
    {
        $valid_characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#$%^*()_-!';
        $valid_len = strlen($valid_characters) - 1;
        $salt = '';

        for ($i = 0; $i < $len; $i++) {
            $salt .= $valid_characters[mt_rand(0, $valid_len)];
        }

        return $salt;
    }

    /**
     * Hash a password using the specified method.
     *
     * @param string $password The password to be hashed.
     * @return string The hashed password.
     * @throws InvalidArgumentException If the password encryption method is invalid.
     */
    public function hashPassword(string $password): string
    {
        if ($this->passwordEncryption === 'bcrypt') {
            return password_hash($password, PASSWORD_BCRYPT, array('cost' => $this->passwordEncryptionCost));
        }

        if ($this->passwordEncryption === 'argon2i') {
            return password_hash($password, PASSWORD_ARGON2I);
        }

        if ($this->passwordEncryption === 'argon2id') {
            return password_hash($password, PASSWORD_ARGON2ID);
        }

        if ($this->passwordEncryption === 'md5salt') {
            return $this->generateCombinedSalt($password);
        }

        if ($this->passwordEncryption === 'md5') {
            return md5($password);
        }

        throw new InvalidArgumentException('Invalid password encryption method');
    }

    /**
     * Verify if a password matches the hashed password.
     *
     * @param string $password The password to be verified.
     * @param string $hash The hashed password.
     * @return bool True if the password matches, false otherwise.
     * @throws InvalidArgumentException If the hash algorithm cannot be determined.
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        $hash_type = $this->identifyHashAlgorithm($hash);

        if ($hash_type === 'md5salt') {
            return $this->constantTimeComparison($this->combineSalts($this->extractUserSalt($hash), $password), $hash);
        }

        if ($hash_type === 'bcrypt' || $hash_type === 'argon2i' || $hash_type === 'argon2id') {
            return password_verify($password, $hash);
        }

        if ($hash_type === 'md5') {
            return $this->constantTimeComparison(md5($password), $hash);
        }

        throw new InvalidArgumentException('Unable to determine hash algorithm');
    }

    /**
     * Check if the hashed password requires rehashing.
     *
     * @param string $hash The hashed password.
     * @return bool True if the password needs rehashing, false otherwise.
     * @throws InvalidArgumentException If the hash algorithm cannot be determined.
     */
    public function requiresRehash(string $hash): bool
    {
        $hash_type = $this->identifyHashAlgorithm($hash);
        if ($hash_type == "unknown") {
            throw new InvalidArgumentException('Unable to determine hash algorithm');
        }

        if ($hash_type !== $this->passwordEncryption) {
            return true;
        }

        if ($hash_type == 'bcrypt') {
            return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->passwordEncryptionCost]);
        }

        if ($hash_type == 'argon2i') {
            return password_needs_rehash($hash, PASSWORD_ARGON2I);
        }

        if ($hash_type == 'argon2id') {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID);
        }

        return false;
    }

    /**
     * Identify the hash algorithm used for the given hash.
     *
     * @param string $hash The hashed password.
     * @return string The hash algorithm name.
     * @throws InvalidArgumentException If the hash algorithm cannot be determined.
     */
    public function identifyHashAlgorithm(string $hash): string
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

    /**
     * Generate a combined salt for the given password.
     *
     * @param string $pass The password.
     * @return string The combined salt.
     */
    public function generateCombinedSalt(string $pass): string
    {
        $salt = $this->generateSalt();
        return $this->combineSalts($salt, $pass);
    }

    /**
     * Combine a salt with a password.
     *
     * @param string $salt The salt.
     * @param string $pass The password.
     * @return string The combined salt and password.
     */
    public function combineSalts(string $salt, string $pass): string
    {
        return md5($salt . $pass) . ':' . $salt;
    }

    /**
     * Extract the user salt from the given password.
     *
     * @param string $password The password.
     * @return string The extracted salt.
     */
    public function extractUserSalt(string $password): string
    {
        return substr(strstr($password, ':'), 1);
    }

    /**
     * Compare two strings using a constant time algorithm.
     *
     * @param string $str1 The first string.
     * @param string $str2 The second string.
     * @return bool True if the strings are equal, false otherwise.
     */
    private function constantTimeComparison(string $str1, string $str2): bool
    {
        if (strlen($str1) !== strlen($str2)) {
            return false;
        }

        $status = 0;
        for ($i = 0; $i < strlen($str1); $i++) {
            $status |= (ord($str1[$i]) ^ ord($str2[$i]));
        }

        return $status === 0;
    }
}
