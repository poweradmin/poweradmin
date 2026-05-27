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

use PDO;

/**
 * Class Permission
 *
 * This class handles permission checks for various actions.
 */
class Permission
{
    /**
     * Record types that holders of zone_content_edit_own_as_client may not modify.
     */
    public const RESTRICTED_TYPES_FOR_CLIENT = ['SOA', 'NS'];

    /**
     * Check whether the given record type is off-limits for a client-level editor.
     *
     * Returns true only when the user is limited to zone_content_edit_own_as_client
     * and the record type is one that requires a stronger edit permission.
     *
     * @param string $type DNS record type (e.g. "A", "SOA", "NS")
     * @param string $permEdit Edit permission level returned by getEditPermission()
     */
    public static function isRecordTypeRestrictedForClient(string $type, string $permEdit): bool
    {
        if ($permEdit !== 'own_as_client') {
            return false;
        }

        return in_array(strtoupper($type), self::RESTRICTED_TYPES_FOR_CLIENT, true);
    }

    /**
     * Localized error message for a restricted-record-type denial.
     *
     * Each branch keeps its gettext string literal so xgettext can extract it
     * into the translation catalog unchanged.
     *
     * @param string $type DNS record type (case-insensitive; only NS and SOA are meaningful)
     * @param 'add'|'edit'|'delete' $action Operation that was denied
     */
    public static function restrictedRecordTypeMessage(string $type, string $action): string
    {
        $isNs = strtoupper($type) === 'NS';

        return match ($action) {
            'add' => $isNs
                ? _('You do not have the permission to add NS record.')
                : _('You do not have the permission to add SOA record.'),
            'edit' => $isNs
                ? _('You do not have the permission to edit this NS record.')
                : _('You do not have the permission to edit this SOA record.'),
            'delete' => $isNs
                ? _('You do not have the permission to delete NS records.')
                : _('You do not have the permission to delete SOA records.'),
        };
    }

    /**
     * Get view permission.
     *
     * This method determines the user's permission to view content.
     *
     * @return string Returns "all", "own", or "none" depending on the user's view permission.
     */
    public static function getViewPermission($db): string
    {
        if (UserManager::verifyPermission($db, 'zone_content_view_others')) {
            return "all";
        } elseif (UserManager::verifyPermission($db, 'zone_content_view_own')) {
            return "own";
        } else {
            return "none";
        }
    }

    /**
     * Get edit permission.
     *
     * This method determines the user's permission to edit content.
     *
     * @return string Returns "all", "own", "own_as_client" or "none" depending on the user's edit permission.
     */
    public static function getEditPermission($db): string
    {
        if (UserManager::verifyPermission($db, 'zone_content_edit_others')) {
            return "all";
        } elseif (UserManager::verifyPermission($db, 'zone_content_edit_own')) {
            return "own";
        } elseif (UserManager::verifyPermission($db, 'zone_content_edit_own_as_client')) {
            return "own_as_client";
        } else {
            return "none";
        }
    }

    /**
     * Get delete permission.
     *
     * This method determines the user's permission to delete zones.
     *
     * @param PDO $db The database connection.
     * @return string Returns "all", "own", or "none" depending on the user's delete permission.
     */
    public static function getDeletePermission(PDO $db): string
    {
        if (UserManager::verifyPermission($db, 'zone_delete_others')) {
            return "all";
        } elseif (UserManager::verifyPermission($db, 'zone_delete_own')) {
            return "own";
        } else {
            return "none";
        }
    }

    /**
     * Get DNSSEC management permission.
     *
     * Gates mutating DNSSEC operations (add/delete/toggle/import/export keys,
     * unsign zone) separately from zone content editing. Ueberusers always
     * receive "all"; everyone else needs the explicit zone_dnssec_manage_own
     * permission and ownership of the zone in question.
     *
     * @param PDO $db The database connection.
     * @return string Returns "all", "own", or "none" depending on the user's DNSSEC management permission.
     */
    public static function getDnssecPermission(PDO $db): string
    {
        if (UserManager::verifyPermission($db, 'user_is_ueberuser')) {
            return "all";
        } elseif (UserManager::verifyPermission($db, 'zone_dnssec_manage_own')) {
            return "own";
        } else {
            return "none";
        }
    }

    /**
     * Get permissions.
     *
     * This method checks a set of permissions for the user.
     *
     * @param PDO $db The database connection.
     * @param array $permissions An array containing the permission keys to check.
     * @return array An associative array containing the permission key and its corresponding boolean value.
     */
    public static function getPermissions(PDO $db, array $permissions): array
    {
        $result = [];

        foreach ($permissions as $permissionName) {
            $result[$permissionName] = UserManager::verifyPermission($db, $permissionName);
        }

        return $result;
    }
}
