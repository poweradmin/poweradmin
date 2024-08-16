<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

namespace Poweradmin;

use PDO;
use Poweradmin\Application\Security\CsrfTokenService;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Infrastructure\Service\RedirectService;

class LegacyAuthenticateSession
{
    private AuthenticationService $authenticationService;
    private PDOLayer $db;
    private LegacyConfiguration $config;
    private UserEventLogger $userEventLogger;
    private LdapUserEventLogger $ldapUserEventLogger;
    private CsrfTokenService $csrfTokenService;
    private LdapAuthenticator $ldapAuthenticator;
    private SqlAuthenticator $sqlAuthenticator;

    public function __construct(PDOLayer $db, LegacyConfiguration $config) {
        $this->db = $db;
        $this->config = $config;

        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authenticationService = new AuthenticationService($sessionService, $redirectService);
        $this->csrfTokenService = new CsrfTokenService();

        $this->userEventLogger = new UserEventLogger($db);
        $this->ldapUserEventLogger = new LdapUserEventLogger($db);

        $this->ldapAuthenticator = new LdapAuthenticator($db, $config, $this->ldapUserEventLogger, $this->authenticationService, $this->csrfTokenService);
        $this->sqlAuthenticator = new SqlAuthenticator($db, $config, $this->userEventLogger, $this->authenticationService, $this->csrfTokenService);
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
        $iface_expire = $this->config->get('iface_expire');
        $session_key = $this->config->get('session_key');
        $ldap_use = $this->config->get('ldap_use');

        if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
            $sessionEntity = new SessionEntity(_('You have logged out.'), 'success');
            $this->authenticationService->logout($sessionEntity);
            return;
        }

        $login_token = $_POST['_token'] ?? '';
        if (isset($_POST['authenticate']) && !$this->csrfTokenService->validateToken($login_token, 'login_token')) {
            $sessionEntity = new SessionEntity(_('Invalid CSRF token.'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        // If a user had just entered his/her login && password, store them in our session.
        if (isset($_POST["authenticate"])) {
            if ($_POST['password'] != '') {
                $passwordEncryptionService = new PasswordEncryptionService($session_key);
                $_SESSION["userpwd"] = $passwordEncryptionService->encrypt($_POST['password']);

                $_SESSION["userlogin"] = $_POST["username"];
                $_SESSION["userlang"] = $_POST["userlang"] ?? $this->config->get('iface_lang');
            } else {
                $sessionEntity = new SessionEntity(_('An empty password is not allowed'), 'danger');
                $this->authenticationService->auth($sessionEntity);
                return;
            }
        }

        // Check if the session hasn't expired yet.
        if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $iface_expire)) {
            $sessionEntity = new SessionEntity(_('Session expired, please login again.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            return;
        }

        // If the session hasn't expired yet, give our session a fresh new timestamp.
        $_SESSION["lastmod"] = time();

        if ($ldap_use && $this->userUsesLDAP()) {
            $this->ldapAuthenticator->authenticate();
        } else {
            $this->sqlAuthenticator->authenticate();
        }
    }

    private function userUsesLDAP(): bool
    {
        if (!isset($_SESSION["userlogin"])) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = :username AND use_ldap = 1");
        $stmt->execute([
            'username' => $_SESSION["userlogin"]
        ]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        return $rowObj !== false;
    }
}
