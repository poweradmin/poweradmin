<?php

require_once("Permissions.class.php");
require_once("util/PoweradminUtil.class.php");

class RfcPermissions
{
    /**
     * @param (int|int[]) $zone_or_zones
     * @return bool Returns true, if the user can create RFCs for the content of the zone / zones. False otherwise.
     */
    public static function can_create_rfc($zone_id_or_zones_ids)
    {
        $zones = PoweradminUtil::make_array($zone_id_or_zones_ids);

        foreach ($zones as $zone_id) {
            if(!self::_can_create_rfc($zone_id)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param (int|int[]) $zone_id_or_zones_ids
     * @return bool Returns true, if the user can edit the content of the zone / zones. False otherwise.
     */
    // TODO: Move out of here, since it has nothing to do with RFCs
    public static function can_edit_zone($zone_id_or_zones_ids)
    {
        $zones = PoweradminUtil::make_array($zone_id_or_zones_ids);

        foreach ($zones as $zone_id) {
            if(!self::_can_edit_zone($zone_id)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return bool Returns true if the user is allowed to see RFCs. False otherwise.
     */
    public static function can_view_rfcs()
    {
        return Permissions::is_godlike()
            || Permissions::can_rfc_other()
            || Permissions::can_rfc_own();
    }

    private static function _can_edit_zone($zone_id)
    {
        return Permissions::is_godlike()
        || Permissions::can_edit_other_content()
        || (Permissions::can_edit_own_content() && Permissions::is_zone_owner($zone_id));
    }

    private static function _can_create_rfc($zone_id)
    {
        return Permissions::is_godlike()
        || Permissions::can_rfc_other()
        || (Permissions::can_rfc_own() && Permissions::is_zone_owner($zone_id));
    }
}
