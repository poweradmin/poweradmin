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
 * ZoneType class represents the different types of zones in a DNS system.
 */
class ZoneType
{
    // Visibility constants for the available zone types
    public const MASTER = "MASTER";
    public const SLAVE = "SLAVE";
    public const NATIVE = "NATIVE";
    public const CONSUMER = "CONSUMER";
    public const PRODUCER = "PRODUCER";

    /**
     * Kinds whose records arrive by transfer from a configured primary.
     *
     * Read by both isReadOnly() and replicatesFromPrimary(), which ask different
     * questions but currently of the same set. Kept in one place so adding a kind
     * cannot silently update one predicate and not the other.
     */
    private const REPLICATING_KINDS = [self::SLAVE, self::CONSUMER];

    /**
     * Get an array of the available zone types.
     *
     * @return array The array of available zone types.
     */
    public static function getTypes(): array
    {
        return [
            self::MASTER,
            self::SLAVE,
            self::NATIVE,
        ];
    }

    /**
     * Secondary (Slave) and Consumer zones replicate their records from a
     * primary, so Poweradmin blocks record edits for them regardless of permissions.
     */
    public static function isReadOnly(?string $type): bool
    {
        return in_array(strtoupper((string)$type), self::REPLICATING_KINDS, true);
    }

    /**
     * Secondary (Slave) and Consumer zones get their records by transfer from a
     * configured primary, so they need masters set and must never be seeded locally.
     * Kept separate from isReadOnly(), which answers whether records may be edited.
     */
    public static function replicatesFromPrimary(?string $type): bool
    {
        return in_array(strtoupper((string)$type), self::REPLICATING_KINDS, true);
    }

    /**
     * Kinds that may be created, given what the connected server supports and
     * whether the caller may add a zone that replicates from a remote primary.
     *
     * @return array<string>
     */
    public static function getCreatableTypes(bool $catalogSupported, bool $mayAddSecondary): array
    {
        $types = [self::MASTER, self::NATIVE];

        if (!$catalogSupported) {
            return $types;
        }

        $types[] = self::PRODUCER;

        if ($mayAddSecondary) {
            $types[] = self::CONSUMER;
        }

        return $types;
    }

    /**
     * @return array<string>
     */
    public static function getReplicatingTypes(): array
    {
        return self::REPLICATING_KINDS;
    }

    /**
     * Every kind PowerDNS accepts. Used as a last-resort guard so an unvalidated
     * caller cannot write an arbitrary string into the zone kind.
     *
     * @return array<string>
     */
    public static function getAllTypes(): array
    {
        return [self::MASTER, self::SLAVE, self::NATIVE, self::PRODUCER, self::CONSUMER];
    }

    /**
     * Primary (Master) and Producer zones send DNS NOTIFY to their secondaries,
     * so a pending-notify state is meaningful only for these; Secondary, Native,
     * and Consumer zones never notify.
     */
    public static function notifies(?string $type): bool
    {
        return in_array(strtoupper((string)$type), [self::MASTER, self::PRODUCER], true);
    }
}
