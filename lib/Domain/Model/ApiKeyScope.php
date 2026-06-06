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

/**
 * Immutable description of what an API key is allowed to do.
 *
 * A key with no restrictions ({@see self::unrestricted()}) behaves exactly like
 * a pre-scope key: every zone and every operation is permitted. Restrictions are
 * additive guards layered on top of the creating user's own permissions - they can
 * only narrow access, never widen it.
 *
 * @package Poweradmin\Domain\Model
 */
final class ApiKeyScope
{
    public const OP_VIEW = 'view';
    public const OP_CREATE = 'create';
    public const OP_UPDATE = 'update';
    public const OP_DELETE = 'delete';

    public const OPERATIONS = [self::OP_VIEW, self::OP_CREATE, self::OP_UPDATE, self::OP_DELETE];

    /**
     * @param int[]|null $zoneIds Zones the key may touch; null means no zone restriction
     * @param string[]|null $operations Operations the key may perform; null means all
     * @param bool $isReadonly When true, only view (GET) requests are allowed
     */
    public function __construct(
        private readonly ?array $zoneIds,
        private readonly ?array $operations,
        private readonly bool $isReadonly
    ) {
    }

    /**
     * A scope that permits everything (the implicit scope of a key with no restrictions).
     */
    public static function unrestricted(): self
    {
        return new self(null, null, false);
    }

    /**
     * Map an HTTP method to the operation it represents.
     */
    public static function methodToOperation(string $method): string
    {
        return match (strtoupper($method)) {
            'POST' => self::OP_CREATE,
            'PUT', 'PATCH' => self::OP_UPDATE,
            'DELETE' => self::OP_DELETE,
            default => self::OP_VIEW,
        };
    }

    /**
     * Whether the operation behind the given HTTP method is allowed by this scope.
     * Convenience wrapper for endpoints whose HTTP method maps directly to one
     * operation; mixed-action endpoints should call {@see self::isOperationTypeAllowed()}.
     */
    public function isOperationAllowed(string $method): bool
    {
        return $this->isOperationTypeAllowed(self::methodToOperation($method));
    }

    /**
     * Whether a specific operation (view/create/update/delete) is allowed by this
     * scope. Use this for endpoints where the HTTP method does not determine the
     * operation - e.g. bulk record changes or dynamic DNS upserts.
     */
    public function isOperationTypeAllowed(string $operation): bool
    {
        if ($this->isReadonly && $operation !== self::OP_VIEW) {
            return false;
        }

        if ($this->operations === null) {
            return true;
        }

        return in_array($operation, $this->operations, true);
    }

    /**
     * Whether the given zone is within this scope.
     */
    public function isZoneAllowed(int $zoneId): bool
    {
        if ($this->zoneIds === null) {
            return true;
        }

        return in_array($zoneId, $this->zoneIds, true);
    }

    public function hasZoneRestriction(): bool
    {
        return $this->zoneIds !== null;
    }

    /**
     * @return int[]|null
     */
    public function getZoneIds(): ?array
    {
        return $this->zoneIds;
    }

    /**
     * @return string[]|null
     */
    public function getOperations(): ?array
    {
        return $this->operations;
    }

    public function isReadonly(): bool
    {
        return $this->isReadonly;
    }
}
