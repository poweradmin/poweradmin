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

use DateTime;
use JsonSerializable;

/**
 * Class ApiKey
 *
 * Represents an API key entity that can be used for API authentication
 *
 * @package Poweradmin\Domain\Model
 */
class ApiKey implements JsonSerializable
{
    private ?int $id = null;
    private string $name;
    private string $secretKey;
    private ?int $createdBy;
    private DateTime $createdAt;
    private ?DateTime $lastUsedAt = null;
    private bool $disabled = false;
    private ?DateTime $expiresAt = null;
    private string $creatorUsername = '';

    /**
     * ApiKey constructor.
     *
     * @param string $name The name of the API key
     * @param string $secretKey The secret key used for authentication
     * @param int|null $createdBy User ID who created this key
     * @param DateTime|null $createdAt When the key was created
     * @param DateTime|null $lastUsedAt When the key was last used
     * @param bool $disabled Whether the key is disabled
     * @param DateTime|null $expiresAt When the key expires (null means no expiration)
     * @param int|null $id Optional ID if retrieved from database
     */
    public function __construct(
        string $name,
        string $secretKey,
        ?int $createdBy = null,
        ?DateTime $createdAt = null,
        ?DateTime $lastUsedAt = null,
        bool $disabled = false,
        ?DateTime $expiresAt = null,
        ?int $id = null
    ) {
        $this->name = $name;
        $this->secretKey = $secretKey;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt ?? new DateTime();
        $this->lastUsedAt = $lastUsedAt;
        $this->disabled = $disabled;
        $this->expiresAt = $expiresAt;
        $this->id = $id;
    }

    /**
     * Generate a new random API key
     *
     * @return string A random API key string
     */
    public static function generateSecretKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Gets the ID of this API key (null if not saved)
     *
     * @return int|null The ID of this API key
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Sets the ID of this API key
     *
     * @param int|null $id The ID to set
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Gets the name of this API key
     *
     * @return string The name of this API key
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the name of this API key
     *
     * @param string $name The name to set
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Gets the secret key of this API key
     *
     * @return string The secret key
     */
    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    /**
     * Sets the secret key of this API key
     *
     * @param string $secretKey The secret key to set
     */
    public function setSecretKey(string $secretKey): void
    {
        $this->secretKey = $secretKey;
    }

    /**
     * Gets the user ID who created this API key
     *
     * @return int|null The user ID
     */
    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    /**
     * Sets the user ID who created this API key
     *
     * @param int|null $createdBy The user ID to set
     */
    public function setCreatedBy(?int $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    /**
     * Gets the creation date of this API key
     *
     * @return DateTime The creation date
     */
    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * Sets the creation date of this API key
     *
     * @param DateTime $createdAt The creation date to set
     */
    public function setCreatedAt(DateTime $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Gets the date this API key was last used
     *
     * @return DateTime|null The last used date, or null if never used
     */
    public function getLastUsedAt(): ?DateTime
    {
        return $this->lastUsedAt;
    }

    /**
     * Sets the date this API key was last used
     *
     * @param DateTime|null $lastUsedAt The last used date to set
     */
    public function setLastUsedAt(?DateTime $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    /**
     * Updates the last used timestamp to now
     */
    public function updateLastUsed(): void
    {
        $this->lastUsedAt = new DateTime();
    }

    /**
     * Checks if this API key is disabled
     *
     * @return bool True if this API key is disabled, false otherwise
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * Sets whether this API key is disabled
     *
     * @param bool $disabled True to disable this API key, false to enable it
     */
    public function setDisabled(bool $disabled): void
    {
        $this->disabled = $disabled;
    }

    /**
     * Gets the expiration date of this API key
     *
     * @return DateTime|null The expiration date, or null if it never expires
     */
    public function getExpiresAt(): ?DateTime
    {
        return $this->expiresAt;
    }

    /**
     * Sets the expiration date of this API key
     *
     * @param DateTime|null $expiresAt The expiration date to set
     */
    public function setExpiresAt(?DateTime $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    /**
     * Checks if this API key has expired
     *
     * @return bool True if this API key has expired, false otherwise
     */
    public function hasExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new DateTime();
    }


    /**
     * Checks if this API key is valid (not expired and not disabled)
     *
     * @return bool True if this API key is valid, false otherwise
     */
    public function isValid(): bool
    {
        return !$this->isDisabled() && !$this->hasExpired();
    }

    /**
     * Regenerate the secret key
     *
     * @return string The new secret key
     */
    public function regenerateSecretKey(): string
    {
        $this->secretKey = self::generateSecretKey();
        return $this->secretKey;
    }

    /**
     * Gets the creator username
     *
     * @return string The creator username
     */
    public function getCreatorUsername(): string
    {
        return $this->creatorUsername;
    }

    /**
     * Sets the creator username
     *
     * @param string $creatorUsername The creator username to set
     */
    public function setCreatorUsername(string $creatorUsername): void
    {
        $this->creatorUsername = $creatorUsername;
    }

    /**
     * Specifies data which should be serialized to JSON
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Do not include secretKey in serialization for security
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'lastUsedAt' => $this->lastUsedAt ? $this->lastUsedAt->format('Y-m-d H:i:s') : null,
            'disabled' => $this->disabled,
            'expiresAt' => $this->expiresAt ? $this->expiresAt->format('Y-m-d H:i:s') : null,
            'isExpired' => $this->hasExpired(),
            'isValid' => $this->isValid(),
            'creatorUsername' => $this->creatorUsername,
        ];
    }
}
