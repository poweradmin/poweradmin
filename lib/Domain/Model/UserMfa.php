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
    private const RECOVERY_HASH_PREFIX = 'sha256:';

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
     * Codes saved before hashing was introduced are hashed here as they are met, so
     * they keep working and stop sitting in the table in the clear.
     *
     * @param string $code The recovery code to validate
     * @return bool True if the code is valid, false otherwise
     */
    public function validateRecoveryCode(#[\SensitiveParameter] string $code): bool
    {
        $codes = array_map(
            fn($stored) => is_string($stored) && !str_starts_with($stored, self::RECOVERY_HASH_PREFIX)
                ? self::hashRecoveryCode($stored)
                : $stored,
            $this->getRecoveryCodesAsArray()
        );
        $candidate = self::hashRecoveryCode($code);

        $valid = false;
        foreach ($codes as $key => $stored) {
            if (is_string($stored) && hash_equals($stored, $candidate)) {
                unset($codes[$key]);
                $valid = true;
                break;
            }
        }

        if ($this->recoveryCodes !== null) {
            $this->setRecoveryCodes(array_values($codes));
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
    public function generateRecoveryCodes(int $count = 10, int $length = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRecoveryCode($length);
        }

        $this->setRecoveryCodes(array_map(self::hashRecoveryCode(...), $codes));

        return $codes;
    }

    /**
     * Codes carry 80 random bits, so an unsalted hash cannot be searched; the prefix
     * keeps a hash apart from a legacy plaintext code of any configured length.
     */
    private static function hashRecoveryCode(#[\SensitiveParameter] string $code): string
    {
        return self::RECOVERY_HASH_PREFIX . hash('sha256', strtolower(trim($code)));
    }

    /**
     * Generate a single recovery code
     *
     * @param int $length Length of the recovery code in bytes (before hex encoding)
     * @return string The generated recovery code
     */
    private function generateRecoveryCode(int $length = 10): string
    {
        return bin2hex(random_bytes($length));
    }
}
