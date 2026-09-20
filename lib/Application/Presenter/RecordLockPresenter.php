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

namespace Poweradmin\Application\Presenter;

use Poweradmin\Domain\Service\ZoneAccessPolicy;

/**
 * Decorates the zone editor's record rows with the display fields the template
 * expects, and with whether each row may be edited.
 *
 * A row is locked by the zone being read-only, by the caller's edit level, or
 * by the NS-subzone rule; the policy itself stays in ZoneAccessPolicy.
 */
class RecordLockPresenter
{
    /**
     * @param array<int, array<string, mixed>> $records Rows as the display service produced them
     * @param string $permEdit The caller's edit level for this zone
     * @param bool $permEditNsSubzone Whether the caller holds the NS-subzone grant
     * @return array<int, array<string, mixed>> The same rows, decorated
     */
    public static function decorate(
        array $records,
        string $zoneName,
        string $permEdit,
        bool $permEditNsSubzone,
        bool $zoneIsReadOnly
    ): array {
        foreach ($records as &$record) {
            $record['display_name'] ??= $record['name'];
            $record['editable_name'] ??= $record['name'];
            $record['unsaved_edit'] = false;
            $record['stored_summary'] = '';

            $nsRecordLocked = ZoneAccessPolicy::isNsRecordLocked(
                $record['type'],
                $permEdit,
                $permEditNsSubzone,
                $record['name'],
                $zoneName
            );
            $record['record_locked'] = ZoneAccessPolicy::isRecordLocked(
                $zoneIsReadOnly,
                $record['type'],
                $permEdit,
                $nsRecordLocked
            );
        }
        unset($record);

        return $records;
    }
}
