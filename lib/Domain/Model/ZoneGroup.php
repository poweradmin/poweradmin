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
 * ZoneGroup domain entity
 *
 * Represents the ownership relationship between a zone (domain) and a group
 */
class ZoneGroup
{
    private ?int $id;
    private int $domainId;
    private int $groupId;
    private ?string $createdAt;
    private ?string $name;
    private ?string $type;

    public function __construct(
        ?int $id,
        int $domainId,
        int $groupId,
        ?string $createdAt = null,
        ?string $name = null,
        ?string $type = null
    ) {
        $this->id = $id;
        $this->domainId = $domainId;
        $this->groupId = $groupId;
        $this->createdAt = $createdAt;
        $this->name = $name;
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Create a new zone-group ownership relationship
     *
     * @param int $domainId Domain/Zone ID
     * @param int $groupId Group ID
     * @return self
     */
    public static function create(
        int $domainId,
        int $groupId
    ): self {
        return new self(null, $domainId, $groupId, null, null, null);
    }
}
