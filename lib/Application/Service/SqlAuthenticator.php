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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

class SqlAuthenticator extends LoggingService
{
    private PDOLayer $connection;
    private ConfigurationManager $configManager;
    private UserEventLogger $userEventLogger;
    private AuthenticationService $authService;
    private CsrfTokenService $csrfTokenService;
    private LoginAttemptService $loginAttemptService;

    public function __construct(
        PDOLayer $connection,
        ConfigurationManager $configManager,
        UserEventLogger $userEventLogger,
        AuthenticationService $authService,
        CsrfTokenService $csrfTokenService,
        Logger $logger,
        LoginAttemptService $loginAttemptService
    ) {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->connection = $connection;

        $this->configManager = $configManager;
        $this->userEventLogger = $userEventLogger;
        $this->authService = $authService;
        $this->csrfTokenService = $csrfTokenService;
        $this->loginAttemptService = $loginAttemptService;
    }

    public function authenticate(): void
    {
        $this->logInfo('Starting authentication process.');

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $username = $_SESSION["userlogin"] ?? '';

        if ($this->loginAttemptService->isAccountLocked($username, $ipAddress)) {
            $this->logWarning('Account is locked for user {username}', ['username' => $username]);
            $sessionEntity = new SessionEntity(_('Account is temporarily locked. Please try again later.'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        $sessionKey = $this->configManager->get('security', 'session_key', '');

        if (!isset($_SESSION["userlogin"]) || !isset($_SESSION["userpwd"])) {
            $this->logWarning('Session variables userlogin or userpwd are not set.');

            $sessionEntity = new SessionEntity('', 'danger');
            $this->authService->auth($sessionEntity);

            $this->logInfo('Authentication process ended due to missing session variables.');
            return;
        }

        $encryptionService = new PasswordEncryptionService($sessionKey);
        $sessionPassword = $encryptionService->decrypt($_SESSION['userpwd']);

        $stmt = $this->connection->prepare("SELECT id, fullname, password, active FROM users WHERE username=:username AND use_ldap=0");
        $stmt->bindParam(':username', $_SESSION["userlogin"]);
        $stmt->execute();
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj) {
            $this->logWarning('No user found with the provided username: {username}', ['username' => $_SESSION["userlogin"]]);
            $this->handleFailedAuthentication();

            $this->logInfo('Authentication process ended due to no user found.');
            return;
        }

        $passwordEncryption = $this->configManager->get('security', 'password_encryption', 'bcrypt');
        $passwordCost = $this->configManager->get('security', 'password_cost', 12);

        $userAuthService = new UserAuthenticationService($passwordEncryption, $passwordCost);

        if (!$userAuthService->verifyPassword($sessionPassword, $rowObj['password'])) {
            $this->logWarning('Password verification failed for user {username}', ['username' => $_SESSION["userlogin"]]);
            $this->loginAttemptService->recordAttempt($username, $ipAddress, false);
            $this->handleFailedAuthentication();

            $this->logInfo('Authentication process ended due to password verification failure.');
            return;
        }

        if ($rowObj['active'] != 1) {
            $this->logWarning('User account is disabled for user {username}', ['username' => $_SESSION["userlogin"]]);
            $sessionEntity = new SessionEntity(_('The user account is disabled.'), 'danger');
            $this->authService->auth($sessionEntity);

            $this->logInfo('Authentication process ended due to disabled user account.');
            return;
        }

        if ($userAuthService->requiresRehash($rowObj['password'])) {
            $this->logInfo('Password requires rehashing for user {username}', ['username' => $_SESSION["userlogin"]]);
            UserManager::update_user_password($this->connection, $rowObj["id"], $sessionPassword);
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
            $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
            $this->userEventLogger->log_successful_auth();
            session_write_close();
            $this->authService->redirectToIndex();
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
        $this->authService->auth($sessionEntity);

        $this->logInfo('Failed authentication handled.');
    }
}

