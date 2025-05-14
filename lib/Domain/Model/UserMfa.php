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

namespace Poweradmin\Domain\Model;

class UserMfa
{
    public const TYPE_APP = 'app';
    public const TYPE_EMAIL = 'email';

    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private bool $enabled,
        private ?string $secret,
        private ?string $recoveryCodes,
        private string $type,
        private ?\DateTime $lastUsedAt,
        private readonly \DateTime $createdAt,
        private ?\DateTime $updatedAt,
        private ?string $verificationData = null
    ) {
    }

    public static function create(
        int $userId,
        bool $enabled = false,
        ?string $secret = null,
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
            new \DateTime(),
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
        $this->updatedAt = new \DateTime();
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->updatedAt = new \DateTime();
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(?string $secret): void
    {
        $this->secret = $secret;
        $this->updatedAt = new \DateTime();
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

    public function setRecoveryCodes(array $recoveryCodes): void
    {
        $this->recoveryCodes = json_encode($recoveryCodes);
        $this->updatedAt = new \DateTime();
    }

    /**
     * Store verification metadata for email-based MFA
     *
     * @param array $metadata The verification metadata
     */
    public function setVerificationMetadata(array $metadata): void
    {
        $this->recoveryCodes = json_encode($metadata);
        $this->updatedAt = new \DateTime();
    }

    /**
     * Set raw JSON string for recovery codes (for backward compatibility)
     *
     * @param string $jsonString JSON string to set
     */
    public function setRecoveryCodesRaw(string $jsonString): void
    {
        $this->recoveryCodes = $jsonString;
        $this->updatedAt = new \DateTime();
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
        $this->updatedAt = new \DateTime();
    }

    /**
     * Set raw verification data (JSON string)
     *
     * @param string $jsonString The verification data as JSON string
     */
    public function setVerificationDataRaw(string $jsonString): void
    {
        $this->verificationData = $jsonString;
        $this->updatedAt = new \DateTime();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        if (!in_array($type, [self::TYPE_APP, self::TYPE_EMAIL])) {
            throw new \InvalidArgumentException("Invalid MFA type: $type");
        }

        $this->type = $type;
        $this->updatedAt = new \DateTime();
    }

    public function getLastUsedAt(): ?\DateTime
    {
        return $this->lastUsedAt;
    }

    public function updateLastUsed(): void
    {
        $this->lastUsedAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    /**
     * Check if a recovery code is valid and remove it from the list if it is
     *
     * @param string $code The recovery code to validate
     * @return bool True if the code is valid, false otherwise
     */
    public function validateRecoveryCode(string $code): bool
    {
        $codes = $this->getRecoveryCodesAsArray();

        if (in_array($code, $codes)) {
            // Remove the used code
            $key = array_search($code, $codes);
            unset($codes[$key]);

            // Update the recovery codes
            $this->setRecoveryCodes(array_values($codes));
            return true;
        }

        return false;
    }

    /**
     * Generate new recovery codes
     *
     * @param int $count Number of recovery codes to generate
     * @return array The generated recovery codes
     */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRecoveryCode();
        }

        $this->setRecoveryCodes($codes);

        return $codes;
    }

    /**
     * Generate a single recovery code
     *
     * @return string The generated recovery code
     */
    private function generateRecoveryCode(): string
    {
        return bin2hex(random_bytes(10));
    }
}
