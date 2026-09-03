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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\Permission;

/**
 * Pure rules for what a user may change on the zone edit screens.
 *
 * Centralizes the permission-level algebra that was previously duplicated
 * across Twig templates.
 */
final class ZoneAccessPolicy
{
    /**
     * Whether the user's edit permission level grants editing this zone at all,
     * independent of the zone type being read-only.
     */
    public static function canEditZone(string $permEdit, bool $userIsZoneOwner): bool
    {
        return $permEdit === 'all'
            || (($permEdit === 'own' || $permEdit === 'own_as_client') && $userIsZoneOwner);
    }

    /**
     * NS records at the zone apex stay locked for own_as_client users; the
     * zone_content_edit_ns_subzone permission unlocks only subzone NS records.
     */
    public static function isNsRecordLocked(
        string $recordType,
        string $permEdit,
        bool $permEditNsSubzone,
        string $recordName,
        ?string $zoneName
    ): bool {
        return $recordType === 'NS'
            && $permEdit === 'own_as_client'
            && !($permEditNsSubzone && Permission::isSubzoneNsRecord($recordType, $recordName, $zoneName));
    }

    /**
     * A locked record renders read-only in the zone edit table: replicated
     * (slave/consumer) zones entirely, SOA for non-"all" editors, and NS
     * records caught by the own_as_client apex rule.
     */
    public static function isRecordLocked(
        bool $zoneIsReadOnly,
        string $recordType,
        string $permEdit,
        bool $nsRecordLocked
    ): bool {
        return $zoneIsReadOnly
            || ($recordType === 'SOA' && $permEdit !== 'all')
            || ($recordType === 'LUA' && $permEdit === 'own_as_client')
            || $nsRecordLocked;
    }
}
