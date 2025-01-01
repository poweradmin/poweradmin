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

class RecordComment {
    private ?int $id;
    private int $domainId;
    private string $name;
    private string $type;
    private int $modifiedAt;
    private ?string $account;
    private string $comment;

    public function __construct(
        ?int $id,
        int $domainId,
        string $name,
        string $type,
        int $modifiedAt,
        ?string $account,
        string $comment
    ) {
        $this->id = $id;
        $this->domainId = $domainId;
        $this->name = $name;
        $this->type = $type;
        $this->modifiedAt = $modifiedAt;
        $this->account = $account;
        $this->comment = $comment;
    }

    public function getId(): ?int { return $this->id; }
    public function getDomainId(): int { return $this->domainId; }
    public function getName(): string { return $this->name; }
    public function getType(): string { return $this->type; }
    public function getModifiedAt(): int { return $this->modifiedAt; }
    public function getAccount(): ?string { return $this->account; }
    public function getComment(): string { return $this->comment; }

    public static function create(
        int $domainId,
        string $name,
        string $type,
        string $comment,
        ?string $account = null
    ): self {
        return new self(
            null,
            $domainId,
            $name,
            $type,
            time(),
            $account,
            $comment
        );
    }

    public function update(
        ?string $name = null,
        ?string $type = null,
        ?string $comment = null,
        ?string $account = null
    ): self {
        return new self(
            $this->id,
            $this->domainId,
            $name ?? $this->name,
            $type ?? $this->type,
            time(),
            $account ?? $this->account,
            $comment ?? $this->comment
        );
    }
}
