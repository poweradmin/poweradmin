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
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\AppConfiguration;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

class SqlAuthenticator extends LoggingService
{
    private PDOLayer $db;
    private AppConfiguration $config;
    private UserEventLogger $userEventLogger;
    private AuthenticationService $authenticationService;
    private CsrfTokenService $csrfTokenService;

    public function __construct(PDOLayer $db, AppConfiguration $config, UserEventLogger $userEventLogger, AuthenticationService $authenticationService, CsrfTokenService $csrfTokenService, Logger $logger)
    {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->db = $db;

        $this->config = $config;
        $this->userEventLogger = $userEventLogger;
        $this->authenticationService = $authenticationService;
        $this->csrfTokenService = $csrfTokenService;
    }

    public function authenticate(): void
    {
        $this->logInfo('Starting authentication process.');

        $session_key = $this->config->get('session_key');

        if (!isset($_SESSION["userlogin"]) || !isset($_SESSION["userpwd"])) {
            $this->logWarning('Session variables userlogin or userpwd are not set.');

            $sessionEntity = new SessionEntity('', 'danger');
            $this->authenticationService->auth($sessionEntity);

            $this->logInfo( 'Authentication process ended due to missing session variables.');
            return;
        }

        $passwordEncryptionService = new PasswordEncryptionService($session_key);
        $session_pass = $passwordEncryptionService->decrypt($_SESSION['userpwd']);

        $stmt = $this->db->prepare("SELECT id, fullname, password, active FROM users WHERE username=:username AND use_ldap=0");
        $stmt->bindParam(':username', $_SESSION["userlogin"]);
        $stmt->execute();
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj) {
            $this->logWarning('No user found with the provided username: {username}', ['username' => $_SESSION["userlogin"]]);
            $this->handleFailedAuthentication();

            $this->logInfo( 'Authentication process ended due to no user found.');
            return;
        }

        $config = new AppConfiguration();
        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );

        if (!$userAuthService->verifyPassword($session_pass, $rowObj['password'])) {
            $this->logWarning('Password verification failed for user {username}', ['username' => $_SESSION["userlogin"]]);
            $this->handleFailedAuthentication();

            $this->logInfo( 'Authentication process ended due to password verification failure.');
            return;
        }

        if ($rowObj['active'] != 1) {
            $this->logWarning('User account is disabled for user {username}', ['username' => $_SESSION["userlogin"]]);
            $sessionEntity = new SessionEntity(_('The user account is disabled.'), 'danger');
            $this->authenticationService->auth($sessionEntity);

            $this->logInfo('Authentication process ended due to disabled user account.');
            return;
        }

        if ($userAuthService->requiresRehash($rowObj['password'])) {
            $this->logInfo('Password requires rehashing for user {username}', ['username' => $_SESSION["userlogin"]]);
            UserManager::update_user_password($this->db, $rowObj["id"], $session_pass);
        }

        session_regenerate_id(true);
        $this->logInfo('Session ID regenerated for user {username}', ['username' => $_SESSION["userlogin"]]);

        $_SESSION['userid'] = $rowObj['id'];
        $_SESSION['name'] = $rowObj['fullname'];
        $_SESSION['auth_used'] = 'internal';

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->csrfTokenService->generateToken();
            $this->logInfo('CSRF token generated for user {username}', ['username' => $_SESSION["userlogin"]]);
        }

        if (isset($_POST['authenticate'])) {
            $this->userEventLogger->log_successful_auth();
            session_write_close();
            $this->authenticationService->redirectToIndex();
        }

        $this->logInfo('Authentication process completed successfully for user {username}', ['username' => $_SESSION["userlogin"]]);
    }

    private function handleFailedAuthentication(): void
    {
        $this->logInfo('Handling failed authentication.');

        if (isset($_POST['authenticate'])) {
            $this->userEventLogger->log_failed_auth();
            $sessionEntity = new SessionEntity(_('Authentication failed!'), 'danger');
        } else {
            unset($_SESSION["userpwd"]);
            unset($_SESSION["userlogin"]);
            $sessionEntity = new SessionEntity(_('Session expired, please login again.'), 'danger');
        }
        $this->authenticationService->auth($sessionEntity);

        $this->logInfo('Failed authentication handled.');
    }
}
