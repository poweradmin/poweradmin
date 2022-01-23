<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Authentication functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once dirname(dirname(dirname(__DIR__))) . '/vendor/poweradmin/Password.php';

/** Authenticate Session
 *
 * Checks if user is logging in, logging out, or session expired and performs
 * actions accordingly
 *
 * @return null
 */
function authenticate_local() {
    global $iface_expire;
    global $session_key;
    global $ldap_use;

    if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
        logout(_('You have logged out.'), 'success');
    }

    // If a user had just entered his/her login && password, store them in our session.
    if (isset($_POST["authenticate"])) {
        if ($_POST['password'] != '') {
            $_SESSION["userpwd"] = base64_encode(openssl_encrypt($_POST['password'], "aes-256-cbc",  md5($session_key), OPENSSL_RAW_DATA, md5(md5($session_key), TRUE)));

            $_SESSION["userlogin"] = $_POST["username"];
            $_SESSION["userlang"] = $_POST["userlang"];
        } else {
            auth(_('An empty password is not allowed'), "error");
        }
    }

    // Check if the session hasn't expired yet.
    if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $iface_expire)) {
        logout(_('Session expired, please login again.'), 'error');
    }

    // If the session hasn't expired yet, give our session a fresh new timestamp.
    $_SESSION["lastmod"] = time();

    if ($ldap_use && userUsesLDAP()) {
        LDAPAuthenticate();
    } else {
        SQLAuthenticate();
    }
}

function userUsesLDAP() {
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

function LDAPAuthenticate() {
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
                log_error(sprintf('Failed LDAP authentication attempt from [%s] Reason: ldap_connect failed', $_SERVER['REMOTE_ADDR']));
            }
            logout(_('Failed to connect to LDAP server!'), 'error');
            return;
        }

        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, $ldap_proto);
        $ldapbind = ldap_bind($ldapconn, $ldap_binddn, $ldap_bindpw);
        if (!$ldapbind) {
            if (isset($_POST["authenticate"])) {
                log_error(sprintf('Failed LDAP authentication attempt from [%s] Reason: ldap_bind failed', $_SERVER['REMOTE_ADDR']));
            }
            logout(_('Failed to bind to LDAP server!'), 'error');
            return;
        }

        $attributes = array($ldap_user_attribute, 'dn');
        $filter = "(" . $ldap_user_attribute . "=" . $_SESSION["userlogin"] . ")";
        $ldapsearch = ldap_search($ldapconn, $ldap_basedn, $filter, $attributes);
        if (!$ldapsearch) {
            if (isset($_POST["authenticate"])) {
                log_error(sprintf('Failed LDAP authentication attempt from [%s] Reason: ldap_search failed', $_SERVER['REMOTE_ADDR']));
            }
            logout(_('Failed to search LDAP.'), 'error');
            return;
        }

        //Checking first that we only found exactly 1 user, get the DN of this user.  We'll use this to perform the actual authentication.
        $entries = ldap_get_entries($ldapconn, $ldapsearch);
        if ($entries["count"] != 1) {
            if (isset($_POST["authenticate"])) {
                if ($entries["count"] == 0) {
                    log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: No such user', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
                } else {
                    log_error(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: Duplicate usernames detected', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
                }
            }
            logout(_('Failed to authenticate against LDAP.'), 'error');
            return;
        }
        $user_dn = $entries[0]["dn"];

        $session_pass = rtrim(openssl_decrypt(base64_decode($_SESSION["userpwd"]), "aes-256-cbc", md5($session_key), OPENSSL_RAW_DATA, md5(md5($session_key), TRUE)) , "\0");;
        $ldapbind = ldap_bind($ldapconn, $user_dn, $session_pass);
        if (!$ldapbind) {
            if (isset($_POST["authenticate"])) {
                log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: Incorrect password', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
            }
            auth(_('LDAP Authentication failed!'), "error");
            return;
        }
        //LDAP AUTH SUCCESSFUL
        //Make sure the user is 'active' and fetch id and name.
        $rowObj = $db->queryRow("SELECT id, fullname FROM users WHERE username=" . $db->quote($_SESSION["userlogin"], 'text') . " AND active=1 AND use_ldap=1");
        if (!$rowObj) {
            if (isset($_POST["authenticate"])) {
                log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: User is inactive', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
            }
            auth(_('LDAP Authentication failed!'), "error");
            return;
        }
        $_SESSION["userid"] = $rowObj["id"];
        $_SESSION["name"] = $rowObj["fullname"];
        $_SESSION["auth_used"] = "ldap";

        if (isset($_POST["authenticate"])) {
            log_notice(sprintf('Successful LDAP authentication attempt from [%s] for user \'%s\'', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
            //If a user has just authenticated, redirect him to requested page
            session_write_close();
            $redirect_url = ($_POST["query_string"] ? $_SERVER['SCRIPT_NAME'] . "?" . $_POST["query_string"] : $_SERVER['SCRIPT_NAME']);
            clean_page($redirect_url);
            exit;
        }
    } else {
        //No username and password set, show auth form (again).
        auth();
    }
}

function SQLAuthenticate() {
    global $db;
    global $session_key;

    if (isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"])) {
        //Username and password are set, lets try to authenticate.
        $session_pass = rtrim(openssl_decrypt(base64_decode($_SESSION["userpwd"]), "aes-256-cbc", md5($session_key), OPENSSL_RAW_DATA, md5(md5($session_key), TRUE)) , "\0");

        $rowObj = $db->queryRow("SELECT id, fullname, password FROM users WHERE username=" . $db->quote($_SESSION["userlogin"], 'text') . " AND active=1 AND use_ldap=0");

        if ($rowObj) {
            if (Poweradmin\Password::verify($session_pass, $rowObj['password'])) {
                if (Poweradmin\Password::needs_rehash($rowObj['password'])) {
                    update_user_password($rowObj["id"], $session_pass);
                }

                $_SESSION["userid"] = $rowObj["id"];
                $_SESSION["name"] = $rowObj["fullname"];
                $_SESSION["auth_used"] = "internal";

                if (isset($_POST["authenticate"])) {
                    log_notice(sprintf('Successful authentication attempt from [%s] for user \'%s\'', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
                    //If a user has just authenticated, redirect him to requested page
                    session_write_close();
                    $redirect_url = ($_POST["query_string"] ? $_SERVER['SCRIPT_NAME'] . "?" . $_POST["query_string"] : $_SERVER['SCRIPT_NAME']);
                    clean_page($redirect_url);
                    exit;
                }
            } else if (isset($_POST['authenticate'])) {
                auth(_('Authentication failed!'), "error");
            } else {
                auth();
            }
        } else if (isset($_POST['authenticate'])) {
            log_warn(sprintf('Failed authentication attempt from [%s]', $_SERVER['REMOTE_ADDR']));
            auth(_('Authentication failed!'), "error");
        } else {
            unset($_SESSION["userpwd"]);
            unset($_SESSION["userlogin"]);
            auth();
        }
    } else {
        // No username and password set, show auth form (again).
        auth();
    }
}
