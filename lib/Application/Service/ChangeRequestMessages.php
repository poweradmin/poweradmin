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

use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;

/**
 * Words a ZoneChangeRequestService outcome for the web flash messages.
 */
class ChangeRequestMessages
{
    public static function submitted(): string
    {
        return _('Your changes were submitted for approval.');
    }

    public static function requiresApproval(): string
    {
        return _('This zone requires approval for changes; use the zone editor to submit a change request.');
    }

    /**
     * The message the zone editor, record forms and the review page show for a
     * filing or decision result.
     */
    public static function forResult(ZoneChangeRequestResult $result): string
    {
        return match ($result->code) {
            ZoneChangeRequestResult::CODE_OK => self::submitted(),
            ZoneChangeRequestResult::CODE_NOT_FOUND => _('There is no change request with this ID.'),
            ZoneChangeRequestResult::CODE_NOT_PENDING => _('This change request has already been decided.'),
            ZoneChangeRequestResult::CODE_NOT_REQUESTER => _('Only the requester can cancel this request.'),
            ZoneChangeRequestResult::CODE_READ_ONLY_ZONE => _('You cannot edit records in a read-only zone.'),
            ZoneChangeRequestResult::CODE_NO_CHANGES => _('Nothing differs from the zone, so no request was filed.'),
            ZoneChangeRequestResult::CODE_TRUNCATED => ZoneSaveMessages::truncated(),
            ZoneChangeRequestResult::CODE_RECORD_NOT_FOUND => _('Record not found.'),
            ZoneChangeRequestResult::CODE_PAYLOAD_TOO_LARGE => _('The change request is too large to store; split it into smaller submissions.'),
            default => $result->message,
        };
    }

    /**
     * The message after a successful approve, reject or cancel.
     */
    public static function forDecision(string $action): string
    {
        return match ($action) {
            'approve' => _('The change request has been approved and applied.'),
            'reject' => _('The change request has been rejected.'),
            default => _('The change request has been cancelled.'),
        };
    }
}
