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

use Poweradmin\Domain\Enum\ZoneKind;

/**
 * ZoneType class represents the different types of zones in a DNS system.
 */
class ZoneType
{
    // Visibility constants for the available zone types
    public const MASTER = ZoneKind::MASTER->value;
    public const SLAVE = ZoneKind::SLAVE->value;
    public const NATIVE = ZoneKind::NATIVE->value;
    public const CONSUMER = ZoneKind::CONSUMER->value;
    public const PRODUCER = ZoneKind::PRODUCER->value;

    /**
     * Get an array of the available zone types.
     *
     * @return array The array of available zone types.
     */
    public static function getTypes(): array
    {
        return ZoneKind::basicValues();
    }

    /**
     * Secondary (Slave) and Consumer zones replicate their records from a
     * primary, so Poweradmin blocks record edits for them regardless of permissions.
     */
    public static function isReadOnly(?string $type): bool
    {
        return ZoneKind::tryFromName($type)?->isReadOnly() ?? false;
    }

    /**
     * Secondary (Slave) and Consumer zones get their records by transfer from a
     * configured primary, so they need masters set and must never be seeded locally.
     * Kept separate from isReadOnly(), which answers whether records may be edited.
     */
    public static function replicatesFromPrimary(?string $type): bool
    {
        return ZoneKind::tryFromName($type)?->replicatesFromPrimary() ?? false;
    }

    /**
     * Kinds that may be created, given what the connected server supports and
     * whether the caller may add a zone that replicates from a remote primary.
     *
     * @return array<string>
     */
    public static function getCreatableTypes(bool $catalogSupported, bool $mayAddSecondary): array
    {
        return ZoneKind::creatableValues($catalogSupported, $mayAddSecondary);
    }

    /**
     * @return array<string>
     */
    public static function getReplicatingTypes(): array
    {
        return [ZoneKind::SLAVE->value, ZoneKind::CONSUMER->value];
    }

    /**
     * Every kind PowerDNS accepts. Used as a last-resort guard so an unvalidated
     * caller cannot write an arbitrary string into the zone kind.
     *
     * @return array<string>
     */
    public static function getAllTypes(): array
    {
        return ZoneKind::values();
    }

    /**
     * Primary (Master) and Producer zones send DNS NOTIFY to their secondaries,
     * so a pending-notify state is meaningful only for these; Secondary, Native,
     * and Consumer zones never notify.
     */
    public static function notifies(?string $type): bool
    {
        return ZoneKind::tryFromName($type)?->notifies() ?? false;
    }
}
