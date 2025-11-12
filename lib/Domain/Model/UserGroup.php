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

/**
 * UserGroup domain entity
 *
 * Represents a group of users with shared permissions via a permission template
 */
class UserGroup
{
    private ?int $id;
    private string $name;
    private ?string $description;
    private int $permTemplId;
    private ?int $createdBy;
    private ?string $createdAt;
    private ?string $updatedAt;

    public function __construct(
        ?int $id,
        string $name,
        ?string $description,
        int $permTemplId,
        ?int $createdBy = null,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->permTemplId = $permTemplId;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getPermTemplId(): int
    {
        return $this->permTemplId;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Create a new UserGroup instance
     *
     * @param string $name Group name (unique)
     * @param int $permTemplId Permission template ID
     * @param string|null $description Optional description
     * @param int|null $createdBy User ID who created the group
     * @return self
     */
    public static function create(
        string $name,
        int $permTemplId,
        ?string $description = null,
        ?int $createdBy = null
    ): self {
        return new self(
            null,
            $name,
            $description,
            $permTemplId,
            $createdBy,
            null,
            null
        );
    }

    /**
     * Update group properties
     *
     * @param string|null $name New name
     * @param string|null $description New description
     * @param int|null $permTemplId New permission template ID
     * @return self
     */
    public function update(
        ?string $name = null,
        ?string $description = null,
        ?int $permTemplId = null
    ): self {
        return new self(
            $this->id,
            $name ?? $this->name,
            $description !== null ? $description : $this->description,
            $permTemplId ?? $this->permTemplId,
            $this->createdBy,
            $this->createdAt,
            null // updatedAt will be set by database trigger
        );
    }
}
