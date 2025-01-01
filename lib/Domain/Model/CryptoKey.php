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

class CryptoKey
{

    private ?int $id;
    private ?string $type;
    private ?int $size;
    private ?string $algorithm;
    private bool $isActive;
    private ?string $dnskey;
    private ?array $ds;

    public function __construct(
        ?int    $id,
        ?string $type = null,
        ?int    $size = null,
        ?string $algorithm = null,
        bool    $isActive = false,
        ?string $dnskey = null,
        ?array  $ds = null
    )
    {
        $this->id = $id;
        $this->type = $type;
        $this->size = $size;
        $this->algorithm = $algorithm;
        $this->isActive = $isActive;
        $this->dnskey = $dnskey;
        $this->ds = $ds;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function getDnskey(): string
    {
        return $this->dnskey;
    }

    public function getDs(): array
    {
        return $this->ds;
    }
}
