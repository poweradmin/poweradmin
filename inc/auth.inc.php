<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
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
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/** Authenticate Session
 *
 * Checks if user is logging in, logging out, or session expired and performs
 * actions accordingly
 *
 * @return null
 */
function doAuthenticate() {
    global $iface_expire;
    global $session_key;
    global $ldap_use;

    if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
        logout(_('You have logged out.'), 'success');
    }

    // If a user had just entered his/her login && password, store them in our session.
    if (isset($_POST["authenticate"])) {
        $_SESSION["userpwd"] = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($session_key), $_POST['password'], MCRYPT_MODE_CBC, md5(md5($session_key))));

        $_SESSION["userlogin"] = $_POST["username"];
        $_SESSION["userlang"] = $_POST["userlang"];
    }

    // Check if the session hasnt expired yet.
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
    global $ldap_debug;
    global $ldap_basedn;
    global $ldap_binddn;
    global $ldap_bindpw;
    global $ldap_proto;
    global $ldap_user_attribute;

    if (isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"])) {
        if ($ldap_debug) {
            ldap_set_option(NULL, LDAP_OPT_DEBUG_LEVEL, 7);
        }
        $ldapconn = ldap_connect($ldap_uri);
        if (!$ldapconn) {
            if (isset($_POST["authenticate"]))
                log_error(sprintf('Failed LDAP authentication attempt from [%s] Reason: ldap_connect failed', $_SERVER['REMOTE_ADDR']));
            logout(_('Failed to connect to LDAP server!'), 'error');
            return;
        }

        $ldapbind = ldap_bind($ldapconn, $ldap_binddn, $ldap_bindpw);
        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, $ldap_proto);
        if (!$ldapbind) {
            if (isset($_POST["authenticate"])) 
                log_error(sprintf('Failed LDAP authentication attempt from [%s] Reason: ldap_bind failed', $_SERVER['REMOTE_ADDR']));
            logout(_('Failed to bind to LDAP server!'), 'error');
            return;
        }

        $attributes = array($ldap_user_attribute, 'dn');
        $filter = "(" . $ldap_user_attribute . "=" . $_SESSION["userlogin"] . ")";
        $ldapsearch = ldap_search($ldapconn, $ldap_basedn, $filter, $attributes);
        if (!$ldapsearch) {
            if (isset($_POST["authenticate"]) ) 
                log_error(sprintf('Failed LDAP authentication attempt from [%s] Reason: ldap_search failed', $_SERVER['REMOTE_ADDR']));
            logout(_('Failed to search LDAP.'), 'error');
            return;
        }

        //Checking first that we only found exactly 1 user, get the DN of this user.  We'll use this to perform the actual authentication.
        $entries = ldap_get_entries($ldapconn, $ldapsearch);
        if ($entries["count"] != 1) {
            if (isset($_POST["authenticate"])) {
                if ($entries["count"] == 0 ){ 
                    log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: No such user', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"])); 
                } else {
                    log_error(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: Duplicate usernames detected', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
                }
            }
            logout(_('Failed to authenticate against LDAP.'), 'error');
            return;
        }
        $user_dn = $entries[0]["dn"];

        $session_pass = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($session_key), base64_decode($_SESSION["userpwd"]), MCRYPT_MODE_CBC, md5(md5($session_key))), "\0");
        $ldapbind = ldap_bind($ldapconn, $user_dn, $session_pass);
        if (!$ldapbind) {
            if (isset($_POST["authenticate"]) ) 
                log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: Incorrect password', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
            auth(_('LDAP Authentication failed!'), "error");
            return;
        }
        //LDAP AUTH SUCCESSFUL
        //Make sure the user is 'active' and fetch id and name.
        $rowObj = $db->queryRow("SELECT id, fullname FROM users WHERE username=" . $db->quote($_SESSION["userlogin"], 'text') . " AND active=1");
        if (!$rowObj) {
            if (isset($_POST["authenticate"]) )
                log_warn(sprintf('Failed LDAP authentication attempt from [%s] for user \'%s\' Reason: User is inactive', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]));
            auth(_('LDAP Authentication failed!'), "error");
            return;
        }
        $_SESSION["userid"] = $rowObj["id"];
        $_SESSION["name"] = $rowObj["fullname"];
        $_SESSION["auth_used"] = "ldap";

        if (isset($_POST["authenticate"])) {
            log_notice( sprintf('Successful LDAP authentication attempt from [%s] for user \'%s\'', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]) );
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
    global $password_encryption;
    global $session_key;

    if (isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"])) {
        //Username and password are set, lets try to authenticate.
        $session_pass = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($session_key), base64_decode($_SESSION["userpwd"]), MCRYPT_MODE_CBC, md5(md5($session_key))), "\0");

        $rowObj = $db->queryRow("SELECT id, fullname, password FROM users WHERE username=" . $db->quote($_SESSION["userlogin"], 'text') . " AND active=1");

        if ($rowObj) {
            if ($password_encryption == 'md5salt') {
                $session_password = mix_salt(extract_salt($rowObj["password"]), $session_pass);
            } else {
                $session_password = md5($session_pass);
            }

            if ($session_password == $rowObj["password"]) {

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
//				auth( _('Authentication failed! - <a href="reset_password.php">(forgot password)</a>'),"error");
                auth(_('Authentication failed!'), "error");
            } else {
                auth();
            }
        } else if (isset($_POST['authenticate'])) {
            log_warn(sprintf('Failed authentication attempt from [%s]', $_SERVER['REMOTE_ADDR']));

            //Authentication failed, retry.
//			auth( _('Authentication failed! - <a href="reset_password.php">(forgot password)</a>'),"error");
            auth(_('Authentication failed!'), "error");
        } else {
            unset($_SESSION["userpwd"]);
            unset($_SESSION["userlogin"]);
            auth();
        }
    } else {
        //No username and password set, show auth form (again).
        auth();
    }
}

/** Print the login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='success', 'error']
 *
 * @return null
 */
function auth($msg = "", $type = "success") {
    include_once('inc/header.inc.php');
    include('inc/config.inc.php');

    if ($msg) {
        print "<div class=\"$type\">$msg</div>\n";
    }
    ?>
    <h2><?php echo _('Log in'); ?></h2>
    <form method="post" action="<?php echo htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES); ?>">
        <input type="hidden" name="query_string" value="<?php echo htmlentities($_SERVER["QUERY_STRING"]); ?>">
        <table border="0">
            <tr>
                <td class="n" width="100"><?php echo _('Username'); ?>:</td>
                <td class="n"><input type="text" class="input" name="username" id="username"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Password'); ?>:</td>
                <td class="n"><input type="password" class="input" name="password"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Language'); ?>:</td>
                <td class="n">
                    <select class="input" name="userlang">
                        <?php
                        // List available languages (sorted alphabetically)
                        include_once('inc/countrycodes.inc.php');
                        $locales = scandir('locale/');
                        foreach ($locales as $locale) {
                            if (strlen($locale) == 5) {
                                $locales_fullname[$locale] = $countrycodes[substr($locale, 0, 2)];
                            }
                        }
                        asort($locales_fullname);
                        foreach ($locales_fullname as $locale => $language) {
                            if ($locale == $iface_lang) {
                                echo _('<option selected value="' . $locale . '">' . $language);
                            } else {
                                echo _('<option value="' . $locale . '">' . $language);
                            }
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="n">&nbsp;</td>
                <td class="n">
                    <input type="submit" name="authenticate" class="button" value=" <?php echo _('Go'); ?> ">
                </td>
            </tr>
        </table>
    </form>
    <script type="text/javascript">
        <!--
      document.getElementById('username').focus();
    //-->
    </script>
    <?php
    include_once('inc/footer.inc.php');
    exit;
}

/** Logout the user
 *
 * Logout the user and kickback to login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='']
 *
 * @return null
 */
function logout($msg = "", $type = "") {
    session_unset();
    session_destroy();
    session_write_close();
    auth($msg, $type);
    exit;
}
