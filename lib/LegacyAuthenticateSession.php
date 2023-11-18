<?php

namespace Poweradmin;

use PDO;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Infrastructure\Service\RedirectService;

class LegacyAuthenticateSession
{
    private AuthenticationService $authenticationService;

    public function __construct() {
        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authenticationService = new AuthenticationService($sessionService, $redirectService);
    }

    /** Authenticate Session
     *
     * Checks if user is logging in, logging out, or session expired and performs
     * actions accordingly
     *
     * @return void
     */
    function authenticate(): void
    {
        global $iface_expire;
        global $session_key;
        global $ldap_use;

        if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
            $sessionEntity = new SessionEntity(_('You have logged out.'), 'success');
            $this->authenticationService->logout($sessionEntity);
        }

        // If a user had just entered his/her login && password, store them in our session.
        if (isset($_POST["authenticate"])) {
            if ($_POST['password'] != '') {
                $passwordEncryptionService = new PasswordEncryptionService($session_key);
                $_SESSION["userpwd"] = $passwordEncryptionService->encrypt($_POST['password']);

                $_SESSION["userlogin"] = $_POST["username"];
                $_SESSION["userlang"] = $_POST["userlang"];
            } else {
                $sessionEntity = new SessionEntity(_('An empty password is not allowed'), 'danger');
                $this->authenticationService->auth($sessionEntity);
            }
        }

        // Check if the session hasn't expired yet.
        if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $iface_expire)) {
            $sessionEntity = new SessionEntity(_('Session expired, please login again.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
        }

        // If the session hasn't expired yet, give our session a fresh new timestamp.
        $_SESSION["lastmod"] = time();

        if ($ldap_use && self::userUsesLDAP()) {
            $this->LDAPAuthenticate();
        } else {
            $this->SQLAuthenticate();
        }
    }

    private static function userUsesLDAP(): bool
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

    private function LDAPAuthenticate(): void
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

        if (!isset($_SESSION["userlogin"]) || !isset($_SESSION["userpwd"])) {
            $sessionEntity = new SessionEntity('', 'danger');
            $this->authenticationService->auth($sessionEntity);
        }

        if ($ldap_debug) {
            ldap_set_option(NULL, LDAP_OPT_DEBUG_LEVEL, 7);
        }

        $ldapconn = ldap_connect($ldap_uri);
        if (!$ldapconn) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_reason('ldap_connect');
            }
            $sessionEntity = new SessionEntity(_('Failed to connect to LDAP server!'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            return;
        }

        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, $ldap_proto);
        $ldapbind = ldap_bind($ldapconn, $ldap_binddn, $ldap_bindpw);
        if (!$ldapbind) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_reason('ldap_bind');
            }
            $sessionEntity = new SessionEntity(_('Failed to bind to LDAP server!'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            return;
        }

        $attributes = array($ldap_user_attribute, 'dn');
        $filter = "(" . $ldap_user_attribute . "=" . $_SESSION["userlogin"] . ")";
        $ldapsearch = ldap_search($ldapconn, $ldap_basedn, $filter, $attributes);
        if (!$ldapsearch) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_reason('ldap_search');
            }
            $sessionEntity = new SessionEntity(_('Failed to search LDAP.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
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
            $sessionEntity = new SessionEntity(_('Failed to authenticate against LDAP.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
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
            $sessionEntity = new SessionEntity(_('LDAP Authentication failed!'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        $rowObj = $db->queryRow("SELECT id, fullname FROM users WHERE username=" . $db->quote($_SESSION["userlogin"], 'text') . " AND active=1 AND use_ldap=1");
        if (!$rowObj) {
            if (isset($_POST["authenticate"])) {
                LdapUserEventLogger::log_failed_user_inactive();
            }
            $sessionEntity = new SessionEntity(_('LDAP Authentication failed!'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }
        $_SESSION["userid"] = $rowObj["id"];
        $_SESSION["name"] = $rowObj["fullname"];
        $_SESSION["auth_used"] = "ldap";

        if (isset($_POST["authenticate"])) {
            LdapUserEventLogger::log_success_auth();
            session_write_close();
            $redirect_url = ($_POST["query_string"] ? $_SERVER['SCRIPT_NAME'] . "?" . $_POST["query_string"] : $_SERVER['SCRIPT_NAME']);
            $this->clean_page($redirect_url);
        }
    }

    private function SQLAuthenticate(): void
    {
        global $db;
        global $session_key;

        if (!isset($_SESSION["userlogin"]) || !isset($_SESSION["userpwd"])) {
            $sessionEntity = new SessionEntity('', 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        $passwordEncryptionService = new PasswordEncryptionService($session_key);
        $session_pass = $passwordEncryptionService->decrypt($_SESSION['userpwd']);

        $stmt = $db->prepare("SELECT id, fullname, password, active FROM users WHERE username=:username AND use_ldap=0");
        $stmt->bindParam(':username', $_SESSION["userlogin"]);
        $stmt->execute();
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj) {
            $this->handleFailedAuthentication();
            return;
        }

        $config = new LegacyConfiguration();
        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );

        if (!$userAuthService->verifyPassword($session_pass, $rowObj['password'])) {
            $this->handleFailedAuthentication();
            return;
        }

        if ($rowObj['active'] != 1) {
            $sessionEntity = new SessionEntity(_('The user account is disabled.'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        if ($userAuthService->requiresRehash($rowObj['password'])) {
            \Poweradmin\update_user_password($rowObj["id"], $session_pass);
        }

        session_regenerate_id(true);

        $_SESSION["userid"] = $rowObj["id"];
        $_SESSION["name"] = $rowObj["fullname"];
        $_SESSION["auth_used"] = "internal";

        if (isset($_POST["authenticate"])) {
            UserEventLogger::log_successful_auth();
            session_write_close();
            $redirect_url = $_POST["query_string"] ? $_SERVER['SCRIPT_NAME'] . "?" . $_POST["query_string"] : $_SERVER['SCRIPT_NAME'];
            $this->clean_page($redirect_url);
        }
    }

    private function handleFailedAuthentication(): void
    {
        if (isset($_POST['authenticate'])) {
            UserEventLogger::log_failed_auth();
            $sessionEntity = new SessionEntity(_('Authentication failed!'), 'danger');
            $this->authenticationService->auth($sessionEntity);
        } else {
            unset($_SESSION["userpwd"]);
            unset($_SESSION["userlogin"]);
            $sessionEntity = new SessionEntity(_('Session expired, please login again.'), 'danger');
            $this->authenticationService->auth($sessionEntity);
        }
    }

    /** Send 302 Redirect with optional argument
     *
     * Reroute a user to a clean page of (if passed) arg
     *
     * @param string $arg argument string to add to url
     *
     * @return void
     */
    private function clean_page(string $arg = ''): void
    {
        if (!$arg) {
            header("Location: " . htmlentities($_SERVER['SCRIPT_NAME'], ENT_QUOTES) . "?time=" . time());
        } else {
            if (preg_match('!\?!si', $arg)) {
                $add = "&time=";
            } else {
                $add = "?time=";
            }
            header("Location: $arg$add" . time());
        }
        exit;
    }
}