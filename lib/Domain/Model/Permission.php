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

use Poweradmin\Domain\Utility\DnsHelper;

/**
 * Permission names and the record-type restrictions for client-level editors.
 */
class Permission
{
    /**
     * Record types that holders of zone_content_edit_own_as_client may not modify.
     */
    public const RESTRICTED_TYPES_FOR_CLIENT = ['SOA', 'NS', 'LUA'];

    /**
     * Permission that lets client-level editors manage NS records below the zone apex.
     */
    public const PERM_EDIT_NS_SUBZONE = 'zone_content_edit_ns_subzone';

    public const PERM_ZONE_MASTER_ADD = 'zone_master_add';
    public const PERM_ZONE_SLAVE_ADD = 'zone_slave_add';
    public const PERM_ZONE_CONTENT_VIEW_OWN = 'zone_content_view_own';
    public const PERM_ZONE_CONTENT_EDIT_OWN = 'zone_content_edit_own';
    public const PERM_ZONE_META_EDIT_OWN = 'zone_meta_edit_own';
    public const PERM_ZONE_CONTENT_VIEW_OTHERS = 'zone_content_view_others';
    public const PERM_ZONE_CONTENT_EDIT_OTHERS = 'zone_content_edit_others';
    public const PERM_ZONE_META_EDIT_OTHERS = 'zone_meta_edit_others';
    public const PERM_SEARCH = 'search';
    public const PERM_SUPERMASTER_VIEW = 'supermaster_view';
    public const PERM_SUPERMASTER_ADD = 'supermaster_add';
    public const PERM_SUPERMASTER_EDIT = 'supermaster_edit';
    public const PERM_USER_IS_UEBERUSER = 'user_is_ueberuser';
    public const PERM_USER_VIEW_OTHERS = 'user_view_others';
    public const PERM_USER_ADD_NEW = 'user_add_new';
    public const PERM_USER_EDIT_OWN = 'user_edit_own';
    public const PERM_USER_EDIT_OTHERS = 'user_edit_others';
    public const PERM_USER_PASSWD_EDIT_OTHERS = 'user_passwd_edit_others';
    public const PERM_USER_EDIT_TEMPL_PERM = 'user_edit_templ_perm';
    public const PERM_TEMPL_PERM_ADD = 'templ_perm_add';
    public const PERM_TEMPL_PERM_EDIT = 'templ_perm_edit';
    public const PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT = 'zone_content_edit_own_as_client';
    public const PERM_ZONE_TEMPL_ADD = 'zone_templ_add';
    public const PERM_ZONE_TEMPL_EDIT = 'zone_templ_edit';
    public const PERM_API_MANAGE_KEYS = 'api_manage_keys';
    public const PERM_ZONE_DELETE_OWN = 'zone_delete_own';
    public const PERM_ZONE_DELETE_OTHERS = 'zone_delete_others';
    public const PERM_USER_ENFORCE_MFA = 'user_enforce_mfa';
    public const PERM_ZONE_DNSSEC_MANAGE_OWN = 'zone_dnssec_manage_own';
    public const PERM_ZONE_LOGS_VIEW_OWN = 'zone_logs_view_own';
    public const PERM_ZONE_LOGS_VIEW_OTHERS = 'zone_logs_view_others';
    public const PERM_USER_LOGS_VIEW = 'user_logs_view';
    public const PERM_GROUP_LOGS_VIEW = 'group_logs_view';
    public const PERM_ZONE_METADATA_VIEW_OWN = 'zone_metadata_view_own';
    public const PERM_ZONE_METADATA_VIEW_OTHERS = 'zone_metadata_view_others';
    public const PERM_ZONE_OWNERSHIP_VIEW_OWN = 'zone_ownership_view_own';
    public const PERM_ZONE_OWNERSHIP_VIEW_OTHERS = 'zone_ownership_view_others';
    public const PERM_ZONE_CHANGE_REQUEST_OWN = 'zone_change_request_own';
    public const PERM_ZONE_CHANGE_REQUEST_OTHERS = 'zone_change_request_others';
    public const PERM_ZONE_CHANGE_APPROVE_OWN = 'zone_change_approve_own';
    public const PERM_ZONE_CHANGE_APPROVE_OTHERS = 'zone_change_approve_others';

    /**
     * Every permission name known to perm_items, for validation and lint tooling.
     */
    public const ALL = [
        self::PERM_ZONE_MASTER_ADD,
        self::PERM_ZONE_SLAVE_ADD,
        self::PERM_ZONE_CONTENT_VIEW_OWN,
        self::PERM_ZONE_CONTENT_EDIT_OWN,
        self::PERM_ZONE_META_EDIT_OWN,
        self::PERM_ZONE_CONTENT_VIEW_OTHERS,
        self::PERM_ZONE_CONTENT_EDIT_OTHERS,
        self::PERM_ZONE_META_EDIT_OTHERS,
        self::PERM_SEARCH,
        self::PERM_SUPERMASTER_VIEW,
        self::PERM_SUPERMASTER_ADD,
        self::PERM_SUPERMASTER_EDIT,
        self::PERM_USER_IS_UEBERUSER,
        self::PERM_USER_VIEW_OTHERS,
        self::PERM_USER_ADD_NEW,
        self::PERM_USER_EDIT_OWN,
        self::PERM_USER_EDIT_OTHERS,
        self::PERM_USER_PASSWD_EDIT_OTHERS,
        self::PERM_USER_EDIT_TEMPL_PERM,
        self::PERM_TEMPL_PERM_ADD,
        self::PERM_TEMPL_PERM_EDIT,
        self::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT,
        self::PERM_ZONE_TEMPL_ADD,
        self::PERM_ZONE_TEMPL_EDIT,
        self::PERM_API_MANAGE_KEYS,
        self::PERM_ZONE_DELETE_OWN,
        self::PERM_ZONE_DELETE_OTHERS,
        self::PERM_USER_ENFORCE_MFA,
        self::PERM_ZONE_DNSSEC_MANAGE_OWN,
        self::PERM_ZONE_LOGS_VIEW_OWN,
        self::PERM_ZONE_LOGS_VIEW_OTHERS,
        self::PERM_USER_LOGS_VIEW,
        self::PERM_GROUP_LOGS_VIEW,
        self::PERM_EDIT_NS_SUBZONE,
        self::PERM_ZONE_METADATA_VIEW_OWN,
        self::PERM_ZONE_METADATA_VIEW_OTHERS,
        self::PERM_ZONE_OWNERSHIP_VIEW_OWN,
        self::PERM_ZONE_OWNERSHIP_VIEW_OTHERS,
        self::PERM_ZONE_CHANGE_REQUEST_OWN,
        self::PERM_ZONE_CHANGE_REQUEST_OTHERS,
        self::PERM_ZONE_CHANGE_APPROVE_OWN,
        self::PERM_ZONE_CHANGE_APPROVE_OTHERS,
    ];

    /**
     * Check whether the given record type is off-limits for a client-level editor.
     *
     * Returns true only when the user is limited to zone_content_edit_own_as_client
     * and the record type is one that requires a stronger edit permission.
     *
     * @param string $type DNS record type (e.g. "A", "SOA", "NS")
     * @param string $permEdit Edit permission level from PermissionService::getEditPermissionLevel()
     */
    public static function isRecordTypeRestrictedForClient(string $type, string $permEdit): bool
    {
        if ($permEdit !== 'own_as_client') {
            return false;
        }

        return in_array(strtoupper($type), self::RESTRICTED_TYPES_FOR_CLIENT, true);
    }

    /**
     * Check whether a record type is off-limits inside a zone template.
     *
     * Template records are written straight to the backend when a template is
     * applied, so they never pass the record-level gates. LUA is held to a
     * stricter standard than the client rule alone: it executes on the DNS
     * server, and a template seeds it into every zone created from it, so it
     * requires the standing to write one directly.
     *
     * @param string $type DNS record type (e.g. "A", "NS", "LUA")
     * @param string $permEdit Edit permission level from PermissionService::getEditPermissionLevel()
     */
    public static function isTemplateRecordTypeRestricted(string $type, string $permEdit): bool
    {
        if (self::isRecordTypeRestrictedForClient($type, $permEdit)) {
            return true;
        }

        return strtoupper($type) === 'LUA' && !in_array($permEdit, ['all', 'own'], true);
    }

    /**
     * Check whether a specific record is off-limits for a client-level editor.
     *
     * Same gate as isRecordTypeRestrictedForClient(), except that holders of
     * zone_content_edit_ns_subzone may manage NS records below the zone apex.
     * SOA and apex NS records stay restricted regardless of that permission.
     *
     * @param string $type DNS record type (e.g. "A", "SOA", "NS")
     * @param string $permEdit Edit permission level from PermissionService::getEditPermissionLevel()
     * @param string|null $recordName Record name (FQDN); null keeps the type-only restriction
     * @param string|null $zoneName Zone name; null keeps the type-only restriction
     * @param bool $canEditSubzoneNs Whether the user holds zone_content_edit_ns_subzone
     */
    public static function isRecordRestrictedForClient(
        string $type,
        string $permEdit,
        ?string $recordName = null,
        ?string $zoneName = null,
        bool $canEditSubzoneNs = false
    ): bool {
        if (!self::isRecordTypeRestrictedForClient($type, $permEdit)) {
            return false;
        }

        return !($canEditSubzoneNs && self::isSubzoneNsRecord($type, $recordName, $zoneName));
    }

    /**
     * Check whether a record is an NS record below the zone apex.
     *
     * This is the record shape zone_content_edit_ns_subzone applies to; the
     * caller supplies the permission check. Unknown names never qualify.
     *
     * @param string $type DNS record type
     * @param string|null $recordName Record name (FQDN); null never qualifies
     * @param string|null $zoneName Zone name; null never qualifies
     */
    public static function isSubzoneNsRecord(string $type, ?string $recordName, ?string $zoneName): bool
    {
        return strtoupper($type) === 'NS'
            && $recordName !== null
            && $zoneName !== null
            && !DnsHelper::isZoneApex($recordName, $zoneName);
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
        $type = strtoupper($type);

        return match ($action) {
            'add' => match ($type) {
                'NS' => _('You do not have the permission to add NS record.'),
                'LUA' => _('You do not have the permission to add LUA record.'),
                default => _('You do not have the permission to add SOA record.'),
            },
            'edit' => match ($type) {
                'NS' => _('You do not have the permission to edit this NS record.'),
                'LUA' => _('You do not have the permission to edit this LUA record.'),
                default => _('You do not have the permission to edit this SOA record.'),
            },
            'delete' => match ($type) {
                'NS' => _('You do not have the permission to delete NS records.'),
                'LUA' => _('You do not have the permission to delete LUA records.'),
                default => _('You do not have the permission to delete SOA records.'),
            },
        };
    }
}
