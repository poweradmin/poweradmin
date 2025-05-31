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
use Poweradmin\Application\Service\LoggingService;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\SqlAuthenticator;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Domain\Service\UserAgreementService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use Poweradmin\Infrastructure\Repository\DbUserAgreementRepository;
use ReflectionClass;

class SessionAuthenticator extends LoggingService
{
    private AuthenticationService $authService;
    private PDOCommon $db;
    private ConfigurationManager $configManager;
    private UserEventLogger $userEventLogger;
    private LdapUserEventLogger $ldapUserEventLogger;
    private CsrfTokenService $csrfTokenService;
    private LdapAuthenticator $ldapAuthenticator;
    private SqlAuthenticator $sqlAuthenticator;
    private LoginAttemptService $loginAttemptService;
    private RecaptchaService $recaptchaService;

    public function __construct(PDOCommon $connection, ConfigurationManager $configManager)
    {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        $loggerLevel = $configManager->get('logging', 'level', 'info');
        parent::__construct(new Logger(LoggerHandlerFactory::create($configManager->getAll()), $loggerLevel), $shortClassName);

        $this->db = $connection;
        $this->configManager = $configManager;

        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authService = new AuthenticationService($sessionService, $redirectService);
        $this->csrfTokenService = new CsrfTokenService();

        $this->userEventLogger = new UserEventLogger($connection);
        $this->ldapUserEventLogger = new LdapUserEventLogger($connection);

        $this->loginAttemptService = new LoginAttemptService($connection, $this->configManager);
        $this->recaptchaService = new RecaptchaService($configManager);

        $this->ldapAuthenticator = new LdapAuthenticator(
            $connection,
            $configManager,
            $this->ldapUserEventLogger,
            $this->authService,
            $this->csrfTokenService,
            $this->logger,
            $this->loginAttemptService
        );
        $this->sqlAuthenticator = new SqlAuthenticator(
            $connection,
            $configManager,
            $this->userEventLogger,
            $this->authService,
            $this->csrfTokenService,
            $this->logger,
            $this->loginAttemptService
        );
    }

    /** Authenticate Session
     *
     * Checks if user is logging in, logging out, or session expired and performs
     * actions accordingly
     *
     * @return void
     */
    public function authenticate(): void
    {
        $this->logDebug('Starting authentication process');

        $iface_expire = $this->configManager->get('interface', 'session_timeout', 1800);
        $session_key = $this->configManager->get('security', 'session_key', '');
        $ldap_use = $this->configManager->get('ldap', 'enabled', false);
        $login_token_validation = $this->configManager->get('security', 'login_token_validation', true);
        $global_token_validation = $this->configManager->get('security', 'global_token_validation', true);

        if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
            $this->logInfo('User {userid} requested logout', ['userid' => $_SESSION['userid']]);
            $sessionEntity = new SessionEntity(_('You have logged out.'), 'success');
            $this->authService->logout($sessionEntity);

            $this->logDebug('Logout process completed for user {userid}', ['userid' => $_SESSION['userid']]);
            return;
        }

        $login_token = $_POST['_token'] ?? '';
        if (
            ($login_token_validation || $global_token_validation)
            && isset($_POST['authenticate'])
            && !$this->csrfTokenService->validateToken($login_token, 'login_token')
        ) {
            $this->logWarning('Invalid CSRF token for user {username}', ['username' => $_POST['username'] ?? 'unknown']);

            $sessionEntity = new SessionEntity(_('Invalid CSRF token.'), 'danger');
            $this->authService->auth($sessionEntity);

            $this->logDebug('CSRF token validation failed for user {username}', ['username' => $_POST['username'] ?? 'unknown']);
            return;
        }

        // If a user had just entered his/her login && password, store them in our session.
        if (isset($_POST["authenticate"])) {
            $this->logDebug('User {username} attempting to authenticate', ['username' => $_POST["username"] ?? 'unknown']);

            // Verify reCAPTCHA if enabled
            if ($this->recaptchaService->isEnabled()) {
                $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
                $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

                if (!$this->recaptchaService->verify($recaptchaResponse, $remoteIp)) {
                    $this->logWarning('reCAPTCHA verification failed for user {username}', ['username' => $_POST['username'] ?? 'unknown']);

                    $sessionEntity = new SessionEntity(_('reCAPTCHA verification failed. Please try again.'), 'danger');
                    $this->authService->auth($sessionEntity);

                    $this->logDebug('Authentication blocked due to reCAPTCHA failure for user {username}', ['username' => $_POST['username'] ?? 'unknown']);
                    return;
                }
            }

            if ($_POST['password'] != '') {
                $passwordEncryptionService = new PasswordEncryptionService($session_key);
                $_SESSION["userpwd"] = $passwordEncryptionService->encrypt($_POST['password']);
                $this->logDebug('Password encrypted for user {username}', ['username' => $_POST["username"]]);

                $_SESSION["userlogin"] = $_POST["username"];
                $this->logDebug('User login set for user {username}', ['username' => $_POST["username"]]);

                $_SESSION["userlang"] = $_POST["userlang"] ?? $this->configManager->get('interface', 'language', 'en_EN');
                $this->logDebug('User language set for user {username}', ['username' => $_POST["username"]]);

                $this->logInfo('User {username} authenticated', ['username' => $_POST["username"]]);
            } else {
                $this->logError('Empty password attempt for user {username}', ['username' => $_POST["username"] ?? 'unknown']);

                $sessionEntity = new SessionEntity(_('An empty password is not allowed'), 'danger');
                $this->authService->auth($sessionEntity);

                $this->logDebug('Authentication failed due to empty password for user {username}', ['username' => $_POST["username"] ?? 'unknown']);
                return;
            }
        }

        // Check if the session hasn't expired yet.
        if (isset($_SESSION["userid"]) && isset($_SESSION["lastmod"]) && $_SESSION["lastmod"] !== "" && ((time() - $_SESSION["lastmod"]) > $iface_expire)) {
            $this->logInfo('Session expired for user {userid}', ['userid' => $_SESSION["userid"]]);

            $sessionEntity = new SessionEntity(_('Session expired, please login again.'), 'danger');
            $this->authService->logout($sessionEntity);

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

        // Check for user agreement requirements after successful authentication
        $this->checkUserAgreementRequirements();

        $this->logDebug('Authentication process completed for user {username}', ['username' => $_SESSION["userlogin"] ?? 'unknown']);
    }

    private function checkUserAgreementRequirements(): void
    {
        $userContextService = new UserContextService();

        // Only check if user is authenticated and not in API context
        if (!$userContextService->isAuthenticated()) {
            return;
        }

        // Skip agreement check for API requests and specific pages
        $currentPage = $_REQUEST['page'] ?? '';
        $skipPages = ['user_agreement', 'logout', 'mfa_verify', 'mfa_setup'];
        if (in_array($currentPage, $skipPages) || strpos($currentPage, 'api/') === 0) {
            return;
        }

        $agreementService = new UserAgreementService(
            new DbUserAgreementRepository($this->db),
            $this->configManager
        );

        $userId = $userContextService->getLoggedInUserId();
        if ($agreementService->isAgreementRequired($userId)) {
            $this->logInfo('User agreement required for user {userid}', ['userid' => $userId]);

            // Redirect to agreement page - user will be sent to index after acceptance
            header('Location: index.php?page=user_agreement');
            exit;
        }
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
