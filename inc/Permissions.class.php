<?php

class ContentEditPermissions
{
    const ALL = 0;
    const OWN = 1;
    const OWN_AS_CLIENT = 2;
    const NONE = 3;
}

class Permissions
{
    /**
     * @var string The hook method to call for verifying permissions.
     */
    private static $VERIFY_PERMISSION = 'verify_permission';

    public static function is_godlike()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'user_is_ueberuser');
    }

    public static function is_zone_owner($zone_id)
    {
        self::assert_user_is_available();
        return self::boolean_hook('verify_user_is_owner_zoneid', $zone_id);
    }

    public static function can_view_own_zone()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_content_view_own');
    }

    public static function can_view_other_zone()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_content_view_others');
    }

    public static function can_rfc_own()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_content_rfc_own');
    }

    public static function can_rfc_other()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_content_rfc_other');
    }

    public static function can_edit_other_content()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_content_edit_others');
    }

    public static function can_edit_own_content()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_content_edit_own');
    }

    public static function can_edit_own_content_as_client()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_content_edit_own_as_client');
    }

    public static function can_edit_own_meta()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_meta_edit_own');
    }

    public static function can_edit_other_meta()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'zone_meta_edit_others');
    }

    ###########################################################################
    # PERMISSIONS WITH MORE THAN 2 RESULT STATES

    public static function get_content_edit_permissions()
    {
        if (self::can_edit_other_content()) {
            return ContentEditPermissions::ALL;
        } elseif (self::can_edit_own_content()) {
            return ContentEditPermissions::OWN;
        } elseif (self::can_edit_own_content_as_client()) {
            return ContentEditPermissions::OWN_AS_CLIENT;
        }
        return ContentEditPermissions::NONE;
    }

    ###########################################################################
    # Legacy permission functions factored out.
    # TODO 5: Use these functions instead of the if else mess everywhere

    public static function get_perm_view_legacy()
    {
        if(self::can_view_other_zone()) { return 'all'; };
        if(self::can_view_own_zone()) { return 'own'; }
        return 'none';
    }

    public static function get_perm_content_edit_legacy()
    {
        if(self::can_edit_other_content()) { return 'all'; }
        if(self::can_edit_own_content()) { return 'own'; }
        if(self::can_edit_own_content_as_client()) { return 'own_as_client'; }
        return 'none';
    }

    public static function get_perm_meta_edit_legacy()
    {
        if(self::can_edit_other_meta()) { return 'all'; }
        if(self::can_edit_own_meta()) { return 'own'; }
        return 'none';
    }

    ###########################################################################
    # HELPER FUNCTIONS

    private static function boolean_hook($func, $args)
    {
        if(do_hook($func, $args)) {
            return true;
        }
        return false;
    }

    private static function assert_user_is_available()
    {
        if(empty($_SESSION["userid"]) || empty($_SESSION['userlogin'])) {
            throw new UnexpectedValueException("Permission data unavailable since no logged-in user was found.");
        }
    }

    public static function can_commit_rfc()
    {
        self::assert_user_is_available();
        return self::boolean_hook(self::$VERIFY_PERMISSION, 'rfc_can_commit');
    }
}
