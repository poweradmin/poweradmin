<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

/**
 * Authentication functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\Application\Services\UserAuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\LdapUserEventLogger;
use Poweradmin\UserEventLogger;

require_once 'inc/session.inc.php';
require_once 'inc/redirect.inc.php';

/** Authenticate Session
 *
 * Checks if user is logging in, logging out, or session expired and performs
 * actions accordingly
 *
 * @return null
 */
function authenticate_local()
{
    global $iface_expire;
    global $session_key;
    global $ldap_use;

    if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
        logout(_('You have logged out.'), 'success');
    }

    // If a user had just entered his/her login && password, store them in our session.
    if (isset($_POST["authenticate"])) {
        if ($_POST['password'] != '') {
            $passwordEncryptionService = new PasswordEncryptionService($session_key);
            $_SESSION["userpwd"] = $passwordEncryptionService->encrypt($_POST['password']);

            $_SESSION["userlogin"] = $_POST["username"];
            $_SESSION["userlang"] = $_POST["userlang"];
        } else {
            auth(_('An empty password is not allowed'), 'danger');
        }
    }

    // Check if the session hasn't expired yet.
    if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $iface_expire)) {
        logout(_('Session expired, please login again.'), 'danger');
    }

    // If the session hasn't expired yet, give our session a fresh new timestamp.
    $_SESSION["lastmod"] = time();

    if ($ldap_use && userUsesLDAP()) {
        LDAPAuthenticate();
    } else {
        SQLAuthenticate();
    }
}

function userUsesLDAP()
{
    if (!isset($_SESSION["userlogin"])) {
        return false;
    }

    global $db;

    $rowObj = $db->queryRow("SELECT id FROM users WHERE username=" . $db->quote($_SESSION["userlogin"], 'text') . " AND use_ldap=1");
    if ($rowObj) {
        return true;
    }
    return false;
}

function LDAPAuthenticate()
{
    global $db;
    global $session_key;
    global $ldap_uri;
    global $ldap_basedn;
    global $ldap_binddn;
    global $ldap_bindpw;
    global $ldap_proto;
    global $ldap_debug;
    global $ldap_user_attribute;

    if (isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"])) {
        if ($ldap_debug) {
            ldap_set_option(NULL, LDAP_OPT_DEBUG_LEVEL, 7);
        }
        $ldapconn = ldap_connect($ldap_uri);
        if (!$ldapconn) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_reason('ldap_connect');
            }
            logout(_('Failed to connect to LDAP server!'), 'danger');
            return;
        }

        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, $ldap_proto);
        $ldapbind = ldap_bind($ldapconn, $ldap_binddn, $ldap_bindpw);
        if (!$ldapbind) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_reason('ldap_bind');
            }
            logout(_('Failed to bind to LDAP server!'), 'danger');
            return;
        }

        $attributes = array($ldap_user_attribute, 'dn');
        $filter = "(" . $ldap_user_attribute . "=" . $_SESSION["userlogin"] . ")";
        $ldapsearch = ldap_search($ldapconn, $ldap_basedn, $filter, $attributes);
        if (!$ldapsearch) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_reason('ldap_search');
            }
            logout(_('Failed to search LDAP.'), 'danger');
            return;
        }

        //Checking first that we only found exactly 1 user, get the DN of this user.  We'll use this to perform the actual authentication.
        $entries = ldap_get_entries($ldapconn, $ldapsearch);
        if ($entries["count"] != 1) {
            if (isset($_POST["authenticate"])) {
                if ($entries["count"] == 0) {
                    LdapUserEventLogger::log_failed_auth();
                } else {
                    LdapUserEventLogger::log_failed_duplicate_auth();
                }
            }
            logout(_('Failed to authenticate against LDAP.'), 'danger');
            return;
        }
        $user_dn = $entries[0]["dn"];

        $passwordEncryptionService = new PasswordEncryptionService($session_key);
        $session_pass = $passwordEncryptionService->decrypt($_SESSION['userpwd']);
        $ldapbind = ldap_bind($ldapconn, $user_dn, $session_pass);
        if (!$ldapbind) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_incorrect_pass();
            }
            auth(_('LDAP Authentication failed!'), 'danger');
            return;
        }
        //LDAP AUTH SUCCESSFUL
        //Make sure the user is 'active' and fetch id and name.
        $rowObj = $db->queryRow("SELECT id, fullname FROM users WHERE username=" . $db->quote($_SESSION["userlogin"], 'text') . " AND active=1 AND use_ldap=1");
        if (!$rowObj) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_user_inactive();
            }
            auth(_('LDAP Authentication failed!'), 'danger');
            return;
        }
        $_SESSION["userid"] = $rowObj["id"];
        $_SESSION["name"] = $rowObj["fullname"];
        $_SESSION["auth_used"] = "ldap";

        if (isset($_POST["authenticate"])) {
            LdapUserEventLogger::log_success_auth();
            //If a user has just authenticated, redirect him to requested page
            session_write_close();
            $redirect_url = ($_POST["query_string"] ? $_SERVER['SCRIPT_NAME'] . "?" . $_POST["query_string"] : $_SERVER['SCRIPT_NAME']);
            clean_page($redirect_url);
        }
    } else {
        //No username and password set, show auth form (again).
        auth();
    }
}

function SQLAuthenticate(): void
{
    global $db;
    global $session_key;

    if (!isset($_SESSION["userlogin"]) || !isset($_SESSION["userpwd"])) {
        // No username and password set, show auth form (again).
        auth();
        return;
    }

    $passwordEncryptionService = new PasswordEncryptionService($session_key);
    $session_pass = $passwordEncryptionService->decrypt($_SESSION['userpwd']);

    $stmt = $db->prepare("SELECT id, fullname, password, active FROM users WHERE username=:username AND use_ldap=0");
    $stmt->bindParam(':username', $_SESSION["userlogin"]);
    $stmt->execute();
    $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rowObj) {
        handleFailedAuthentication();
        return;
    }

    global $password_encryption, $password_encryption_cost;
    $userAuthService = new UserAuthenticationService($password_encryption, $password_encryption_cost);
    if (!$userAuthService->verifyPassword($session_pass, $rowObj['password'])) {
        handleFailedAuthentication();
        return;
    }

    if ($rowObj['active'] != 1) {
        auth(_('The user account is disabled.'), 'danger');
        return;
    }

    if ($userAuthService->requiresRehash($rowObj['password'])) {
        update_user_password($rowObj["id"], $session_pass);
    }

    session_regenerate_id(true);

    $_SESSION["userid"] = $rowObj["id"];
    $_SESSION["name"] = $rowObj["fullname"];
    $_SESSION["auth_used"] = "internal";

    if (isset($_POST["authenticate"])) {
        UserEventLogger::log_successful_auth();
        session_write_close();
        $redirect_url = $_POST["query_string"] ? $_SERVER['SCRIPT_NAME'] . "?" . $_POST["query_string"] : $_SERVER['SCRIPT_NAME'];
        clean_page($redirect_url);
    }
}

function handleFailedAuthentication(): void
{
    if (isset($_POST['authenticate'])) {
        UserEventLogger::log_failed_auth();
        auth(_('Authentication failed!'), 'danger');
    } else {
        unset($_SESSION["userpwd"]);
        unset($_SESSION["userlogin"]);
        auth();
    }
}
