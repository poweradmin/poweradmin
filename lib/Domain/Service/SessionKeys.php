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
 * Central catalogue of $_SESSION keys.
 *
 * Search and list views deliberately use different sort buckets so a column
 * picked in one view cannot leak into a query that does not support it.
 */
final class SessionKeys
{
    // Sort/filter state (search vs list-zones use distinct buckets to prevent
    // a sort column picked in one view from leaking into a query that does
    // not support it - historical regression: ORDER BY domains.owner).
    public const LIST_ZONE_SORT_BY = 'list_zone_sort_by';
    public const SEARCH_ZONE_SORT_BY = 'zone_sort_by';
    public const SEARCH_RECORD_SORT_BY = 'record_sort_by';
    public const REVERSE_ZONE_TYPE = 'reverse_zone_type';
    public const LETTER = 'letter';

    // Auth identity
    public const AUTHENTICATED = 'authenticated';
    public const AUTH_USED = 'auth_used';
    public const AUTH_METHOD_USED = 'auth_method_used';
    public const USER_ID = 'user_id';
    public const USERID = 'userid';
    public const USERLOGIN = 'userlogin';
    public const USEREMAIL = 'useremail';
    public const USERFULLNAME = 'userfullname';
    public const USERPWD = 'userpwd';
    public const USERPASSWD = 'userpasswd';
    public const USERLANG = 'userlang';
    public const USERTYPE = 'usertype';
    public const USERLEVEL = 'userlevel';
    public const NAME = 'name';
    public const EMAIL = 'email';
    public const LASTMOD = 'lastmod';

    // Pending identity (held during MFA verification, promoted on success)
    public const PENDING_USERID = 'pending_userid';
    public const PENDING_NAME = 'pending_name';
    public const PENDING_EMAIL = 'pending_email';
    public const PENDING_AUTH_USED = 'pending_auth_used';

    // MFA
    public const MFA_REQUIRED = 'mfa_required';
    public const MFA_STATUS = 'mfa_status';
    public const MFA_TOKEN = 'mfa_token';
    public const MFA_VERIFICATION_TOKEN = 'mfa_verification_token';
    public const MFA_SETUP_ENFORCED = 'mfa_setup_enforced';

    // OIDC / OAuth
    public const OIDC_AUTHENTICATED = 'oidc_authenticated';
    public const OIDC_PROVIDER = 'oidc_provider';
    public const OIDC_STATE = 'oidc_state';
    public const OAUTH_AVATAR_URL = 'oauth_avatar_url';

    // SAML
    public const SAML_AUTHENTICATED = 'saml_authenticated';
    public const SAML_PROVIDER = 'saml_provider';
    public const SAML_SESSION_INDEX = 'saml_session_index';
    public const SAML_SLO_PENDING = 'saml_slo_pending';

    // LDAP rate-limit
    public const LDAP_AUTH_IP = 'ldap_auth_ip';
    public const LDAP_AUTH_TIMESTAMP = 'ldap_auth_timestamp';
    public const LDAP_AUTH_USERNAME = 'ldap_auth_username';

    // Tokens
    public const CSRF_TOKEN = 'csrf_token';
    public const INSTALL_TOKEN = 'install_token';
    public const LOGIN_TOKEN = 'login_token';
    public const PASSWORD_RESET_TOKEN = 'password_reset_token';
    public const RESET_PASSWORD_TOKEN = 'reset_password_token';
    public const USERNAME_RECOVERY_TOKEN = 'username_recovery_token';

    // Flash messages
    public const LOGIN_MESSAGE = 'message';
    public const LOGIN_MESSAGE_TYPE = 'type';
    public const MESSAGES = 'messages';

    // Form scratchpad (per-controller transient state)
    public const ADD_RECORD_ERROR = 'add_record_error';
    public const ADD_RECORD_LAST_DATA = 'add_record_last_data';
    public const ADD_RECORD_ZONE_ID = 'add_record_zone_id';
    public const ZONE_IMPORT_DATA = 'zone_import_data';

    // Misc
    public const PDNS_VERSION_LAST_ATTEMPT = 'pdns_version_last_attempt';

    private function __construct()
    {
    }
}
