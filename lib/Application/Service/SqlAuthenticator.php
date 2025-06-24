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
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionClass;

class SqlAuthenticator extends LoggingService
{
    private PDOCommon $connection;
    private ConfigurationManager $configManager;
    private UserEventLogger $userEventLogger;
    private $authService; // Can be either AuthenticationService or UserAuthenticationService
    private CsrfTokenService $csrfTokenService;
    private LoginAttemptService $loginAttemptService;
    private array $serverParams;
    private ?MfaService $mfaService = null;

    public function __construct(
        PDOCommon $connection,
        ConfigurationManager $configManager,
        UserEventLogger $userEventLogger,
        $authService, // Changed type to allow UserAuthenticationService
        CsrfTokenService $csrfTokenService,
        Logger $logger,
        LoginAttemptService $loginAttemptService,
        array $serverParams = []
    ) {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->connection = $connection;
        $this->configManager = $configManager;
        $this->userEventLogger = $userEventLogger;
        $this->authService = $authService;
        $this->csrfTokenService = $csrfTokenService;
        $this->loginAttemptService = $loginAttemptService;
        $this->serverParams = $serverParams ?: $_SERVER;

        // Initialize MFA service
        $userMfaRepository = new DbUserMfaRepository($connection);
        $mailService = new MailService($configManager);
        $this->mfaService = new MfaService($userMfaRepository, $configManager, $mailService);
    }

    public function authenticate(): void
    {
        $this->logInfo('Starting authentication process.');

        // Get the client IP using the IpAddressRetriever
        $ipRetriever = new IpAddressRetriever($this->serverParams);
        $ipAddress = $ipRetriever->getClientIp() ?: '0.0.0.0';
        $username = $_SESSION["userlogin"] ?? '';

        if ($this->loginAttemptService->isAccountLocked($username, $ipAddress)) {
            $this->logWarning('Account is locked for user {username}', ['username' => $username]);
            $sessionEntity = new SessionEntity(_('Account is temporarily locked. Please try again later.'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        $sessionKey = $this->configManager->get('security', 'session_key');

        if (!isset($_SESSION["userlogin"]) || !isset($_SESSION["userpwd"])) {
            $this->logWarning('Session variables userlogin or userpwd are not set.');

            $sessionEntity = new SessionEntity('', 'danger');
            $this->authService->auth($sessionEntity);

            $this->logInfo('Authentication process ended due to missing session variables.');
            return;
        }

        $encryptionService = new PasswordEncryptionService($sessionKey);
        $sessionPassword = $encryptionService->decrypt($_SESSION['userpwd']);

        $stmt = $this->connection->prepare("SELECT id, fullname, password, active, email FROM users WHERE username=:username AND use_ldap=0");
        $stmt->bindParam(':username', $_SESSION["userlogin"]);
        $stmt->execute();
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj) {
            $this->logWarning('No user found with the provided username: {username}', ['username' => $_SESSION["userlogin"]]);
            $this->handleFailedAuthentication();

            $this->logInfo('Authentication process ended due to no user found.');
            return;
        }

        $passwordEncryption = $this->configManager->get('security', 'password_encryption');
        $passwordCost = $this->configManager->get('security', 'password_cost');

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
            UserManager::updateUserPassword($this->connection, $rowObj["id"], $sessionPassword);
        }

        session_regenerate_id(true);
        $this->logInfo('Session ID regenerated for user {username}', ['username' => $_SESSION["userlogin"]]);

        $_SESSION['userid'] = $rowObj['id'];
        $_SESSION['name'] = $rowObj['fullname'];
        $_SESSION['email'] = $rowObj['email'];
        $_SESSION['auth_used'] = 'internal';

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->csrfTokenService->generateToken();
            $this->logInfo('CSRF token generated for user {username}', ['username' => $_SESSION["userlogin"]]);
        }

        // Check if MFA is globally enabled
        $mfaGloballyEnabled = $this->configManager->get('security', 'mfa.enabled', false);

        // Check if MFA is enabled for this user
        $mfaRequired = $mfaGloballyEnabled && $this->mfaService->isMfaEnabled($rowObj['id']);

        if ($mfaRequired) {
            $this->logInfo('MFA is required for user {username}', ['username' => $_SESSION["userlogin"]]);

            // Use our centralized MFA session manager to set MFA required
            MfaSessionManager::setMfaRequired($rowObj['id']);

            if (isset($_POST['authenticate'])) {
                $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
                $this->userEventLogger->logSuccessfulAuth();

                // Log before redirect
                error_log("SqlAuthenticator: Redirecting to MFA verification page");

                // Clear any output buffers
                if (ob_get_level()) {
                    ob_end_clean();
                }

                // Redirect to MFA verification page
                header("Location: index.php?page=mfa_verify", true, 302);
                exit;
            }
        } else {
            // No MFA required, proceed with full authentication
            $_SESSION['authenticated'] = true;
            $_SESSION['mfa_required'] = false;

            if (isset($_POST['authenticate'])) {
                $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
                $this->userEventLogger->logSuccessfulAuth();
                session_write_close();
                $this->authService->redirectToIndex();
            }
        }

        $this->logInfo('Authentication process completed successfully for user {username}', ['username' => $_SESSION["userlogin"]]);
    }

    private function handleFailedAuthentication(): void
    {
        $this->logInfo('Handling failed authentication.');

        if (isset($_POST['authenticate'])) {
            $this->userEventLogger->logFailedAuth();
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
