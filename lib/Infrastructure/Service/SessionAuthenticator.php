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

namespace Poweradmin\Infrastructure\Service;

use PDO;
use Poweradmin\AppConfiguration;
use Poweradmin\Application\Service\LoggingService;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\SqlAuthenticator;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use ReflectionClass;

class SessionAuthenticator extends LoggingService
{
    private AuthenticationService $authenticationService;
    private PDOLayer $db;
    private AppConfiguration $config;
    private UserEventLogger $userEventLogger;
    private LdapUserEventLogger $ldapUserEventLogger;
    private CsrfTokenService $csrfTokenService;
    private LdapAuthenticator $ldapAuthenticator;
    private SqlAuthenticator $sqlAuthenticator;

    public function __construct(PDOLayer $db, AppConfiguration $config) {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct(new Logger(LoggerHandlerFactory::create($config->getAll()), $config->get('logger_level')), $shortClassName);

        $this->db = $db;
        $this->config = $config;

        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authenticationService = new AuthenticationService($sessionService, $redirectService);
        $this->csrfTokenService = new CsrfTokenService();

        $this->userEventLogger = new UserEventLogger($db);
        $this->ldapUserEventLogger = new LdapUserEventLogger($db);

        $this->ldapAuthenticator = new LdapAuthenticator($db, $config, $this->ldapUserEventLogger, $this->authenticationService, $this->csrfTokenService, $this->logger);
        $this->sqlAuthenticator = new SqlAuthenticator($db, $config, $this->userEventLogger, $this->authenticationService, $this->csrfTokenService, $this->logger);
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
        $this->logDebug('Starting authentication process');

        $iface_expire = $this->config->get('iface_expire');
        $session_key = $this->config->get('session_key');
        $ldap_use = $this->config->get('ldap_use');
        $login_token_validation = $this->config->isLoginTokenValidationEnabled();
        $global_token_validation = $this->config->isGlobalTokenValidationEnabled();

        if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
            $this->logInfo('User {userid} requested logout', ['userid' => $_SESSION['userid']]);
            $sessionEntity = new SessionEntity(_('You have logged out.'), 'success');
            $this->authenticationService->logout($sessionEntity);

            $this->logDebug('Logout process completed for user {userid}', ['userid' => $_SESSION['userid']]);
            return;
        }

        $login_token = $_POST['_token'] ?? '';
        if (($login_token_validation || $global_token_validation)
            && isset($_POST['authenticate'])
            && !$this->csrfTokenService->validateToken($login_token, 'login_token')
        ) {
            $this->logWarning('Invalid CSRF token for user {username}', ['username' => $_POST['username'] ?? 'unknown']);

            $sessionEntity = new SessionEntity(_('Invalid CSRF token.'), 'danger');
            $this->authenticationService->auth($sessionEntity);

            $this->logDebug('CSRF token validation failed for user {username}', ['username' => $_POST['username'] ?? 'unknown']);
            return;
        }

        // If a user had just entered his/her login && password, store them in our session.
        if (isset($_POST["authenticate"])) {
            $this->logDebug('User {username} attempting to authenticate', ['username' => $_POST["username"] ?? 'unknown']);

            if ($_POST['password'] != '') {
                $passwordEncryptionService = new PasswordEncryptionService($session_key);
                $_SESSION["userpwd"] = $passwordEncryptionService->encrypt($_POST['password']);
                $this->logDebug('Password encrypted for user {username}', ['username' => $_POST["username"]]);

                $_SESSION["userlogin"] = $_POST["username"];
                $this->logDebug('User login set for user {username}', ['username' => $_POST["username"]]);

                $_SESSION["userlang"] = $_POST["userlang"] ?? $this->config->get('iface_lang');
                $this->logDebug('User language set for user {username}', ['username' => $_POST["username"]]);

                $this->logInfo('User {username} authenticated', ['username' => $_POST["username"]]);
            } else {
                $this->logError('Empty password attempt for user {username}', ['username' => $_POST["username"] ?? 'unknown']);

                $sessionEntity = new SessionEntity(_('An empty password is not allowed'), 'danger');
                $this->authenticationService->auth($sessionEntity);

                $this->logDebug('Authentication failed due to empty password for user {username}', ['username' => $_POST["username"] ?? 'unknown']);
                return;
            }
        }

        // Check if the session hasn't expired yet.
        if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $iface_expire)) {
            $this->logInfo('Session expired for user {userid}', ['userid' => $_SESSION["userid"]]);

            $sessionEntity = new SessionEntity(_('Session expired, please login again.'), 'danger');
            $this->authenticationService->logout($sessionEntity);

            $this->logDebug('Session expired and user {userid} logged out', ['userid' => $_SESSION["userid"]]);
            return;
        }

        // If the session hasn't expired yet, give our session a fresh new timestamp.
        $_SESSION["lastmod"] = time();
        $this->logDebug('Session timestamp updated for user {username}', ['username' => $_SESSION["userlogin"] ?? 'unknown']);

        if ($ldap_use && $this->userUsesLDAP()) {
            $this->logInfo('User {username} uses LDAP for authentication', ['username' => $_SESSION["userlogin"]]);
            $this->ldapAuthenticator->authenticate();
        } else {
            $this->logInfo('User {username} uses SQL for authentication', ['username' => $_SESSION["userlogin"] ?? 'unknown']);
            $this->sqlAuthenticator->authenticate();
        }

        $this->logDebug('Authentication process completed for user {username}', ['username' => $_SESSION["userlogin"] ?? 'unknown']);
    }

    private function userUsesLDAP(): bool
    {
        if (!isset($_SESSION["userlogin"])) {
            $this->logDebug('No user login found in session');
            return false;
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = :username AND use_ldap = 1");
        $stmt->execute([
            'username' => $_SESSION["userlogin"]
        ]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->logDebug('Checked LDAP usage for user {username}', ['username' => $_SESSION["userlogin"]]);

        $ldapUsage = $rowObj !== false;
        $this->logDebug('LDAP usage for user {username}: {ldapUsage}', ['username' => $_SESSION["userlogin"], 'ldapUsage' => $ldapUsage]);

        return $ldapUsage;
    }
}
