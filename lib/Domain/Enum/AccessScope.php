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


namespace Poweradmin\Domain\Enum;

/**
 * How far a permission reaches.
 *
 * Produced by the getters on {@see \Poweradmin\Domain\Model\Permission}, which
 * keep returning the raw string because templates compare it directly. Use the
 * predicates here rather than re-listing cases at a comparison site.
 *
 * Note the vocabulary is not uniform: only the edit permission can ever be
 * OWN_AS_CLIENT.
 */
enum AccessScope: string
{
    case NONE = 'none';
    case OWN = 'own';
    case OWN_AS_CLIENT = 'own_as_client';
    case ALL = 'all';

    /**
     * Read a permission string, treating anything unrecognised as NONE so an
     * unexpected value denies rather than grants.
     */
    public static function fromString(?string $value): self
    {
        return $value === null ? self::NONE : (self::tryFrom($value) ?? self::NONE);
    }

    /**
     * Whether the scope is limited to zones the user owns.
     *
     * Replaces the `$perm == 'own' || $perm == 'own_as_client'` disjunction; the
     * two differ in what may be edited, not in whose zones are reachable.
     */
    public function isOwnedOnly(): bool
    {
        return $this === self::OWN || $this === self::OWN_AS_CLIENT;
    }

    /**
     * Whether the scope grants anything at all.
     */
    public function grantsAnything(): bool
    {
        return $this !== self::NONE;
    }

    /**
     * Whether the scope reaches every zone regardless of ownership.
     */
    public function isUnrestricted(): bool
    {
        return $this === self::ALL;
    }
}
