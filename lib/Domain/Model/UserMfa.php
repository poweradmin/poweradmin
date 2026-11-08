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

namespace Poweradmin\Domain\Model;

use DateTime;
use InvalidArgumentException;
use Poweradmin\Domain\Enum\MfaFactorType;

/**
 * A user's MFA settings row: type (app or email), secret, recovery codes, verification data and timestamps.
 */
class UserMfa
{
    public const TYPE_APP = 'app';
    public const TYPE_EMAIL = 'email';
    private const RECOVERY_SHA256_PREFIX = 'sha256:';
    private const RECOVERY_BCRYPT_COST = 9;

    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private bool $enabled,
        #[\SensitiveParameter] private ?string $secret,
        private ?string $recoveryCodes,
        private string $type,
        private ?DateTime $lastUsedAt,
        private readonly DateTime $createdAt,
        private ?DateTime $updatedAt,
        private ?string $verificationData = null
    ) {
    }

    public static function create(
        int $userId,
        bool $enabled = false,
        #[\SensitiveParameter] ?string $secret = null,
        ?string $recoveryCodes = null,
        string $type = self::TYPE_APP,
        ?string $verificationData = null
    ): self {
        return new self(
            0,
            $userId,
            $enabled,
            $secret,
            $recoveryCodes,
            $type,
            null,
            new DateTime(),
            null,
            $verificationData
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
        $this->updatedAt = new DateTime();
    }

    /**
     * Turns the factor off and drops its secret, recovery codes and pending code, so
     * nothing from the old enrolment can be used or revived by a later one.
     */
    public function disable(): void
    {
        $this->enabled = false;
        $this->secret = null;
        $this->recoveryCodes = null;
        $this->verificationData = null;
        $this->type = self::TYPE_APP;
        $this->updatedAt = new DateTime();
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->secret = $secret;
        $this->updatedAt = new DateTime();
    }

    public function getRecoveryCodes(): ?string
    {
        return $this->recoveryCodes;
    }

    public function getRecoveryCodesAsArray(): array
    {
        if ($this->recoveryCodes === null) {
            return [];
        }

        return json_decode($this->recoveryCodes, true) ?? [];
    }

    private function setRecoveryCodes(array $recoveryCodes): void
    {
        $this->recoveryCodes = json_encode($recoveryCodes);
        $this->updatedAt = new DateTime();
    }

    /**
     * Get verification data
     *
     * @return string|null The verification data
     */
    public function getVerificationData(): ?string
    {
        return $this->verificationData;
    }

    /**
     * Get verification data as array
     *
     * @return array The verification data as array
     */
    public function getVerificationDataAsArray(): array
    {
        if ($this->verificationData === null) {
            return [];
        }

        return json_decode($this->verificationData, true) ?? [];
    }

    /**
     * Set verification data
     *
     * @param array $data The verification data to set
     */
    public function setVerificationData(array $data): void
    {
        $this->verificationData = json_encode($data);
        $this->updatedAt = new DateTime();
    }

    /**
     * Set raw verification data (JSON string)
     *
     * @param string $jsonString The verification data as JSON string
     */
    public function setVerificationDataRaw(string $jsonString): void
    {
        $this->verificationData = $jsonString;
        $this->updatedAt = new DateTime();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        if (!MfaFactorType::isValid($type)) {
            throw new InvalidArgumentException("Invalid MFA type: $type");
        }

        $this->type = $type;
        $this->updatedAt = new DateTime();
    }

    /**
     * The configured factor as an enum, or null for a type this build no longer knows.
     */
    public function getFactorType(): ?MfaFactorType
    {
        return MfaFactorType::tryFrom($this->type);
    }

    public function getLastUsedAt(): ?DateTime
    {
        return $this->lastUsedAt;
    }

    public function updateLastUsed(): void
    {
        $this->lastUsedAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    /**
     * Check if a recovery code is valid and remove it from the list if it is
     *
     * Every stored entry is checked. Bcrypt hashes, "sha256:" hashes and legacy plaintext
     * codes are all accepted; plaintext entries are rewritten as bcrypt as they are met.
     * SHA-256 entries cannot be converted without the code, so they stay until used.
     *
     * @param string $code The recovery code to validate
     * @return bool True if the code is valid, false otherwise
     */
    public function validateRecoveryCode(#[\SensitiveParameter] string $code): bool
    {
        $candidate = strtolower(trim($code));
        $sha256 = self::RECOVERY_SHA256_PREFIX . hash('sha256', $candidate);
        // crypt() stops at a NUL byte, which would let a prefix of a code match
        $bcryptSafe = !str_contains($candidate, "\0");

        $kept = [];
        $valid = false;
        foreach ($this->getRecoveryCodesAsArray() as $stored) {
            if (!is_string($stored)) {
                continue;
            }

            $plaintext = false;
            if (self::isBcryptHash($stored)) {
                $match = $bcryptSafe && password_verify($candidate, $stored);
            } elseif (self::isSha256Hash($stored)) {
                $match = hash_equals($stored, $sha256);
            } else {
                $match = hash_equals(strtolower(trim($stored)), $candidate);
                $plaintext = true;
            }

            if ($match && !$valid) {
                $valid = true;
                continue;
            }
            $kept[] = $plaintext ? self::hashRecoveryCode($stored) : $stored;
        }

        if ($this->recoveryCodes !== null) {
            $this->setRecoveryCodes($kept);
        }

        return $valid;
    }

    /**
     * Generate new recovery codes
     *
     * Only the hashes are kept, so the returned list is the one chance to show them.
     *
     * @param int $count Number of recovery codes to generate
     * @param int $length Length of each recovery code in bytes (before hex encoding)
     * @return array The generated recovery codes
     */
    public function generateRecoveryCodes(int $count = 10, int $length = 5): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRecoveryCode($length);
        }

        $this->setRecoveryCodes(array_map(self::hashRecoveryCode(...), $codes));

        return $codes;
    }

    /**
     * Short look-up secrets are salted and stored with bcrypt. Cost 9 is about 27 ms
     * per hash, so checking a full list of 10 codes stays near a quarter of a second.
     */
    private static function hashRecoveryCode(#[\SensitiveParameter] string $code): string
    {
        return password_hash(strtolower(trim($code)), PASSWORD_BCRYPT, ['cost' => self::RECOVERY_BCRYPT_COST]);
    }

    private static function isBcryptHash(string $stored): bool
    {
        return preg_match('/^\$2y\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $stored) === 1;
    }

    private static function isSha256Hash(string $stored): bool
    {
        return preg_match('/^sha256:[0-9a-f]{64}$/', $stored) === 1;
    }

    /**
     * Generate a single recovery code
     *
     * @param int $length Length of the recovery code in bytes (before hex encoding)
     * @return string The generated recovery code
     */
    private function generateRecoveryCode(int $length = 5): string
    {
        return bin2hex(random_bytes($length));
    }
}
