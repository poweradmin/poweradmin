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

/**
 * Pure rules for the opt-in change approval workflow: whether a user's zone
 * changes are written directly, filed as a change request, or refused, and who
 * may review a request.
 */
final class ChangeApprovalPolicy
{
    /** Changes are written to the zone immediately (today's behaviour). */
    public const MODE_DIRECT = 'direct';

    /** Changes are stored as a change request that a reviewer applies later. */
    public const MODE_REQUEST = 'request';

    /** The user may neither change the zone nor request changes to it. */
    public const MODE_NONE = 'none';

    /**
     * How the user's changes to a zone are handled.
     *
     * @param bool $enabled approval.enabled
     * @param bool $requireReviewForAll approval.require_review_for_all
     * @param string $permEdit edit level for the zone: all, own, own_as_client or none
     * @param string $permRequest change request level: all, own or none
     * @return string one of the MODE_* constants
     */
    public static function mode(
        bool $enabled,
        bool $requireReviewForAll,
        string $permEdit,
        string $permRequest,
        bool $userIsZoneOwner
    ): string {
        $canEdit = ZoneAccessPolicy::canEditZone($permEdit, $userIsZoneOwner);

        if (!$enabled) {
            return $canEdit ? self::MODE_DIRECT : self::MODE_NONE;
        }

        $canRequest = ZoneAccessPolicy::levelAppliesToZone($permRequest, $userIsZoneOwner);

        if ($requireReviewForAll) {
            return ($canEdit || $canRequest) ? self::MODE_REQUEST : self::MODE_NONE;
        }

        if ($canEdit) {
            return self::MODE_DIRECT;
        }

        return $canRequest ? self::MODE_REQUEST : self::MODE_NONE;
    }

    /**
     * Reviewing needs both the approve level and the edit permission: the
     * record writers re-check the reviewer's edit rights when a request is applied,
     * so the approve permission adds to edit rights and never replaces them.
     *
     * @param string $permApprove change approve level: all, own or none
     * @param string $permEdit edit level for the zone: all, own, own_as_client or none
     */
    public static function canReview(string $permApprove, string $permEdit, bool $userIsZoneOwner): bool
    {
        return ZoneAccessPolicy::levelAppliesToZone($permApprove, $userIsZoneOwner)
            && ZoneAccessPolicy::canEditZone($permEdit, $userIsZoneOwner);
    }
}
