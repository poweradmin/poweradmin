<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\AppConfiguration;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

class LdapAuthenticator extends LoggingService
{
    private PDOLayer $db;
    private AppConfiguration $config;
    private LdapUserEventLogger $ldapUserEventLogger;
    private AuthenticationService $authenticationService;
    private CsrfTokenService $csrfTokenService;

    public function __construct(PDOLayer $db, AppConfiguration $config, LdapUserEventLogger $ldapUserEventLogger, AuthenticationService $authenticationService, CsrfTokenService $csrfTokenService, Logger $logger)
    {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->db = $db;
        $this->config = $config;
        $this->ldapUserEventLogger = $ldapUserEventLogger;
        $this->authenticationService = $authenticationService;
        $this->csrfTokenService = $csrfTokenService;
    }

    public function authenticate(): void
    {
        $this->logInfo( 'Starting LDAP authentication process.');

        $session_key = $this->config->get('session_key');
        $ldap_uri = $this->config->get('ldap_uri');
        $ldap_basedn = $this->config->get('ldap_basedn');
        $ldap_search_filter = $this->config->get('ldap_search_filter');
        $ldap_binddn = $this->config->get('ldap_binddn');
        $ldap_bindpw = $this->config->get('ldap_bindpw');
        $ldap_proto = $this->config->get('ldap_proto');
        $ldap_debug = $this->config->get('ldap_debug');
        $ldap_user_attribute = $this->config->get('ldap_user_attribute');

        if (!isset($_SESSION["userlogin"]) || !isset($_SESSION["userpwd"])) {
            $this->logWarning('Session variables userlogin or userpwd are not set.');
            $sessionEntity = new SessionEntity('', 'danger');
            $this->authenticationService->auth($sessionEntity);
            $this->logInfo( 'LDAP authentication process ended due to missing session variables.');
            return;
        }

        if ($ldap_debug) {
            ldap_set_option(NULL, LDAP_OPT_DEBUG_LEVEL, 7);
        }

        $ldapconn = ldap_connect($ldap_uri);
        if (!$ldapconn) {
            $this->logError('Failed to connect to LDAP server.');
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->log_failed_reason('ldap_connect');
            }
            $sessionEntity = new SessionEntity(_('Failed to connect to LDAP server!'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to connection failure.');
            return;
        }

        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, $ldap_proto);
        $ldapbind = ldap_bind($ldapconn, $ldap_binddn, $ldap_bindpw);
        if (!$ldapbind) {
            $this->logError('Failed to bind to LDAP server.');
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->log_failed_reason('ldap_bind');
            }
            $sessionEntity = new SessionEntity(_('Failed to bind to LDAP server!'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to bind failure.');
            return;
        }

        $attributes = array($ldap_user_attribute, 'dn');
        $filter = $ldap_search_filter
            ? "(&($ldap_user_attribute={$_SESSION['userlogin']})$ldap_search_filter)"
            : "($ldap_user_attribute={$_SESSION['userlogin']})";

        if ($ldap_debug) {
            echo "<div class=\"container\"><pre>";
            echo sprintf("LDAP search filter: %s\n", $filter);
            echo "</pre></div>";
        }

        $ldapsearch = ldap_search($ldapconn, $ldap_basedn, $filter, $attributes);
        if (!$ldapsearch) {
            $this->logError('Failed to search LDAP.');
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->log_failed_reason('ldap_search');
            }
            $sessionEntity = new SessionEntity(_('Failed to search LDAP.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            $this->logInfo( 'LDAP authentication process ended due to search failure.');
            return;
        }

        $entries = ldap_get_entries($ldapconn, $ldapsearch);
        if ($entries["count"] != 1) {
            $this->logWarning('LDAP search did not return exactly one user. Count: {count}', ['count' => $entries["count"]]);
            if (isset($_POST["authenticate"])) {
                if ($entries["count"] == 0) {
                    $this->ldapUserEventLogger->log_failed_auth();
                } else {
                    $this->ldapUserEventLogger->log_failed_duplicate_auth();
                }
            }
            $sessionEntity = new SessionEntity(_('Failed to authenticate against LDAP.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to incorrect user count.');
            return;
        }
        $user_dn = $entries[0]["dn"];

        $passwordEncryptionService = new PasswordEncryptionService($session_key);
        $session_pass = $passwordEncryptionService->decrypt($_SESSION['userpwd']);
        $ldapbind = ldap_bind($ldapconn, $user_dn, $session_pass);
        if (!$ldapbind) {
            $this->logWarning('LDAP authentication failed for user {username}', ['username' => $_SESSION['userlogin']]);
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->log_failed_incorrect_pass();
            }
            $sessionEntity = new SessionEntity(_('LDAP Authentication failed!'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            $this->logInfo( 'LDAP authentication process ended due to incorrect password.');
            return;
        }

        $stmt = $this->db->prepare("SELECT id, fullname FROM users WHERE username = :username AND active = 1 AND use_ldap = 1");
        $stmt->execute([
            'username' => $_SESSION["userlogin"]
        ]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj) {
            $this->logWarning('No active LDAP user found with the provided username: {username}', ['username' => $_SESSION["userlogin"]]);
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->log_failed_user_inactive();
            }
            $sessionEntity = new SessionEntity(_('LDAP Authentication failed!'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            $this->logInfo( 'LDAP authentication process ended due to no active user found.');
            return;
        }

        session_regenerate_id(true);
        $this->logInfo( 'Session ID regenerated for user {username}', ['username' => $_SESSION["userlogin"]]);

        $_SESSION['userid'] = $rowObj['id'];
        $_SESSION['name'] = $rowObj['fullname'];
        $_SESSION['auth_used'] = 'ldap';

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->csrfTokenService->generateToken();
            $this->logInfo( 'CSRF token generated for user {username}', ['username' => $_SESSION["userlogin"]]);
        }

        if (isset($_POST['authenticate'])) {
            $this->ldapUserEventLogger->log_success_auth();
            session_write_close();
            $this->authenticationService->redirectToIndex();
        }

        $this->logInfo( 'LDAP authentication process completed successfully for user {username}', ['username' => $_SESSION["userlogin"]]);
    }
}