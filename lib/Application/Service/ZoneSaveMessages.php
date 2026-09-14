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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Enum\ZoneSaveOutcome;
use Poweradmin\Domain\Service\ZoneSaveResult;

/**
 * Words a ZoneEditService save for the zone editor's flash message.
 */
class ZoneSaveMessages
{
    /**
     * @return array{0: string, 1: string}|null Message type and text, or null when there is nothing to say
     */
    public static function forResult(ZoneSaveResult $result): ?array
    {
        return match ($result->outcome) {
            ZoneSaveOutcome::FORBIDDEN => ['error', _('You do not have permission to edit this zone.')],
            ZoneSaveOutcome::READ_ONLY => ['error', _('You cannot edit records in a read-only zone.')],
            ZoneSaveOutcome::WRITE_FAILED => ['error', _('Zone has not been updated successfully.')],
            ZoneSaveOutcome::SERIAL_CONFLICT => ['warning', _('Request has expired, please try again.')],
            ZoneSaveOutcome::UPDATED => ['success', _('Zone has been updated successfully.')],
            ZoneSaveOutcome::NO_CHANGES => $result->serialBumped
                ? ['info', _('Zone saved successfully. No record changes were made, but SOA serial was incremented.')]
                : ['info', _('Zone saved successfully. No record changes were made.')],
            ZoneSaveOutcome::NOTHING_SAVED => null,
        };
    }

    public static function truncated(): string
    {
        return _('Some records were not saved because the form exceeded the server limit on the number of fields. Ask your administrator to increase the PHP "max_input_vars" setting.');
    }
}
