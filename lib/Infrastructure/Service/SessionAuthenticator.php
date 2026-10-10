<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\UserAgreementService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use Poweradmin\Infrastructure\Repository\DbUserAgreementRepository;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Application\Service\MailService;
use ReflectionClass;

class SessionAuthenticator extends LoggingService
{
    public const GATE_SKIP = 'skip';
    public const GATE_JSON = 'json';
    public const GATE_REDIRECT = 'redirect';

    private AuthenticationService $authService;
    private PDO $db;
    private ConfigurationManager $configManager;
    private UserEventLogger $userEventLogger;
    private LdapUserEventLogger $ldapUserEventLogger;
    private CsrfTokenService $csrfTokenService;
    private LdapAuthenticator $ldapAuthenticator;
    private SqlAuthenticator $sqlAuthenticator;
    private LoginAttemptService $loginAttemptService;
    private RecaptchaService $recaptchaService;
    private RedirectService $redirectService;

    public function __construct(PDO $connection, ConfigurationManager $configManager)
    {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        $loggerLevel = $configManager->get('logging', 'level', 'info');
        parent::__construct(new Logger(LoggerHandlerFactory::create($configManager->getAll()), $loggerLevel), $shortClassName);

        $this->db = $connection;
        $this->configManager = $configManager;

        $sessionService = new SessionService();
        $this->redirectService = new RedirectService();
        $this->authService = new AuthenticationService($sessionService, $this->redirectService);
        $this->csrfTokenService = new CsrfTokenService();

        $this->userEventLogger = new UserEventLogger($connection);
        $this->ldapUserEventLogger = new LdapUserEventLogger($connection);

        $this->loginAttemptService = new LoginAttemptService($connection, $this->configManager);
        $this->recaptchaService = new RecaptchaService($configManager);

        $userContextService = new UserContextService();
        $this->ldapAuthenticator = new LdapAuthenticator(
            $connection,
            $configManager,
            $this->ldapUserEventLogger,
            $this->authService,
            $this->csrfTokenService,
            $this->logger,
            $this->loginAttemptService,
            $userContextService
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

        // Logout is now handled by LogoutController via /logout route

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
                $remoteIp = (new IpAddressRetriever($_SERVER))->getClientIp();

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

            $auditService = new AuditService($this->db);
            $auditService->logSessionExpired();

            $sessionEntity = new SessionEntity(_('Session expired, please login again.'), 'danger');
            $this->authService->logout($sessionEntity);

            $this->logDebug('Session expired and user {userid} logged out', ['userid' => $_SESSION["userid"]]);
            return;
        }

        // If the session hasn't expired yet, give our session a fresh new timestamp.
        $_SESSION["lastmod"] = time();
        $this->logDebug('Session timestamp updated for user {username}', ['username' => $_SESSION["userlogin"] ?? 'unknown']);

        $authMethod = $this->getUserAuthMethod();

        switch ($authMethod) {
            case UserProvisioningService::AUTH_METHOD_OIDC:
                $this->logInfo('User {username} uses OIDC for authentication - skipping password verification', ['username' => $_SESSION["userlogin"] ?? 'unknown']);
                // OIDC users are already authenticated, no need to verify password
                $this->endSessionOfDisabledAccount();
                break;
            case UserProvisioningService::AUTH_METHOD_SAML:
                $this->logInfo('User {username} uses SAML for authentication - skipping password verification', ['username' => $_SESSION["userlogin"] ?? 'unknown']);
                // SAML users are already authenticated, no need to verify password
                $this->endSessionOfDisabledAccount();
                break;
            case UserProvisioningService::AUTH_METHOD_LDAP:
                if ($ldap_use) {
                    $this->logInfo('User {username} uses LDAP for authentication', ['username' => $_SESSION["userlogin"]]);
                    $this->ldapAuthenticator->authenticate();
                } else {
                    $this->logWarning('User {username} configured for LDAP but LDAP is disabled', ['username' => $_SESSION["userlogin"]]);
                    $sessionEntity = new SessionEntity(_('LDAP authentication is disabled'), 'danger');
                    $this->authService->logout($sessionEntity);
                }
                break;
            case 'sql':
            default:
                if (isset($_SESSION["userlogin"])) {
                    $this->logInfo('User {username} uses SQL for authentication', ['username' => $_SESSION["userlogin"]]);
                }
                $this->sqlAuthenticator->authenticate();
                break;
        }

        // Check for user agreement requirements after successful authentication
        $this->checkUserAgreementRequirements();

        // Check for MFA enforcement requirements after user agreement
        $this->checkMfaEnforcementRequirements();

        $this->logDebug('Authentication process completed for user {username}', ['username' => $_SESSION["userlogin"] ?? 'unknown']);
    }

    private function checkUserAgreementRequirements(): void
    {
        $userContextService = new UserContextService();

        // Only check if user is authenticated and not in API context
        if (!$userContextService->isAuthenticated()) {
            return;
        }

        // Get the current request path (without base_url_prefix)
        $currentPath = $this->getCurrentRequestPath();

        $action = self::gateAction($currentPath, ['/user-agreement', '/logout', '/mfa/verify', '/mfa/setup'], $_SERVER['REQUEST_METHOD'] ?? 'GET', $this->strictSessionGates());
        if ($action === self::GATE_SKIP) {
            return;
        }

        $agreementService = new UserAgreementService(
            new DbUserAgreementRepository($this->db, $this->configManager),
            $this->configManager
        );

        $userId = $userContextService->getLoggedInUserId();
        if ($userId && $agreementService->isAgreementRequired($userId)) {
            $this->logInfo('User agreement required for user {userid}', ['userid' => $userId]);

            if ($action === self::GATE_JSON) {
                $this->haltInternalApi('User agreement must be accepted first');
            }

            // Redirect to agreement page - user will be sent to index after acceptance
            $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
            $this->redirectService->redirectTo($baseUrlPrefix . '/user-agreement');
        }
    }

    private function checkMfaEnforcementRequirements(): void
    {
        $userContextService = new UserContextService();

        // Only check if user is authenticated
        if (!$userContextService->isAuthenticated()) {
            return;
        }

        // Get the current request path (without base_url_prefix)
        $currentPath = $this->getCurrentRequestPath();

        $action = self::gateAction($currentPath, ['/logout', '/mfa/verify', '/mfa/setup'], $_SERVER['REQUEST_METHOD'] ?? 'GET', $this->strictSessionGates());
        if ($action === self::GATE_SKIP) {
            return;
        }

        $userId = $userContextService->getLoggedInUserId();
        if (!$userId) {
            return;
        }

        // Create MFA service to check enforcement
        $mfaService = new MfaService(
            new DbUserMfaRepository($this->db, $this->configManager),
            $this->configManager,
            new MailService($this->configManager)
        );

        // Check if MFA setup is required for this user
        if ($mfaService->isMfaSetupRequired($userId, $this->db)) {
            $this->logInfo('MFA setup required for user {userid}', ['userid' => $userId]);

            if ($action === self::GATE_JSON) {
                $this->haltInternalApi('Multi-factor authentication setup required');
            }

            // Set a session flag to indicate this is an enforced setup
            $_SESSION['mfa_setup_enforced'] = true;

            // Redirect to MFA setup page
            $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
            $this->redirectService->redirectTo($baseUrlPrefix . '/mfa/setup');
        }
    }

    /**
     * Get the current request path without base_url_prefix.
     *
     * This extracts the path from REQUEST_URI and strips the base_url_prefix
     * if configured, returning just the application route path.
     *
     * @return string The request path (e.g., '/mfa/setup', '/zones/forward')
     */
    private function getCurrentRequestPath(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query string if present
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        // Strip base_url_prefix if configured
        $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
        if (!empty($baseUrlPrefix) && str_starts_with($path, $baseUrlPrefix)) {
            $path = substr($path, strlen($baseUrlPrefix));
            if (empty($path)) {
                $path = '/';
            }
        }

        return $path;
    }

    /**
     * How a session gate (user agreement, MFA enrolment) treats a request path.
     *
     * Without security.strict_session_gates every path containing /api/ is skipped,
     * as before 4.3.7.
     *
     * With it, the key-authenticated public APIs carry no session and are skipped. The
     * internal API runs on the session, so it is held back with a JSON 403 until the
     * user complies, except the preference read every page makes, the setup pages
     * included. Matching is on whole API path segments, so a web page such as
     * /settings/api/logs is still redirected.
     *
     * @param string $currentPath Request path without base_url_prefix
     * @param string[] $skipPaths Pages the gate itself sends the user to
     * @param string $method HTTP method; only a read of the preferences is let through
     */
    public static function gateAction(string $currentPath, array $skipPaths, string $method = 'GET', bool $strict = true): string
    {
        foreach ($skipPaths as $skipPath) {
            if ($currentPath === $skipPath || str_starts_with($currentPath, $skipPath . '/')) {
                return self::GATE_SKIP;
            }
        }

        if (!$strict) {
            return str_contains($currentPath, '/api/') ? self::GATE_SKIP : self::GATE_REDIRECT;
        }

        if (preg_match('#/api/v\d+(/|$)#', $currentPath) === 1) {
            return self::GATE_SKIP;
        }

        if (preg_match('#/api/internal(/|$)#', $currentPath) === 1) {
            return strtoupper($method) === 'GET' && str_ends_with(rtrim($currentPath, '/'), '/api/internal/user-preferences')
                ? self::GATE_SKIP
                : self::GATE_JSON;
        }

        return self::GATE_REDIRECT;
    }

    private function strictSessionGates(): bool
    {
        return (bool)$this->configManager->get('security', 'strict_session_gates', false);
    }

    private function haltInternalApi(string $message): never
    {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => $message]);
        exit;
    }

    /**
     * SQL and LDAP sessions re-check the account on every request; OIDC and SAML
     * sessions would not, so a disabled or deleted account ends here. Both ids are
     * checked, as a signed-in user may start another login that needs MFA.
     */
    private function endSessionOfDisabledAccount(): void
    {
        foreach (['userid', 'pending_userid'] as $key) {
            $userId = $_SESSION[$key] ?? null;
            if (!is_numeric($userId) || (int)$userId <= 0) {
                continue;
            }

            $stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id AND active = 1');
            $stmt->bindValue(':id', (int)$userId, PDO::PARAM_INT);
            $stmt->execute();
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->logWarning('Ending session of disabled account {userId}', ['userId' => (int)$userId]);
                $this->authService->logout(new SessionEntity(_('The user account is disabled.'), 'danger'));
                return;
            }
        }
    }

    private function getUserAuthMethod(): string
    {
        if (!isset($_SESSION["userlogin"])) {
            $this->logDebug('No user login found in session');
            return 'sql'; // Default to SQL if no user logged in
        }

        // First check how the current session was created
        if (isset($_SESSION["auth_method_used"])) {
            $sessionAuthMethod = $_SESSION["auth_method_used"];
            $this->logDebug('Using session auth method for user {username}: {authMethod}', [
                'username' => $_SESSION["userlogin"],
                'authMethod' => $sessionAuthMethod
            ]);
            return $sessionAuthMethod;
        }

        // Fall back to database auth_method (for existing SQL/LDAP sessions)
        try {
            $stmt = $this->db->prepare("SELECT auth_method FROM users WHERE username = :username");
            $stmt->execute([
                'username' => $_SESSION["userlogin"]
            ]);
            $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($rowObj === false) {
                $this->logWarning('User {username} not found in database', ['username' => $_SESSION["userlogin"]]);
                return 'sql'; // Default to SQL if user not found
            }

            $authMethod = $rowObj['auth_method'] ?? 'sql';
            $this->logDebug('Using database auth method for user {username}: {authMethod}', [
                'username' => $_SESSION["userlogin"],
                'authMethod' => $authMethod
            ]);

            return $authMethod;
        } catch (\PDOException $e) {
            $this->logError('Database error while fetching auth method for user {username}: {error}', [
                'username' => $_SESSION["userlogin"],
                'error' => $e->getMessage()
            ]);

            // Log out user and display error message
            $sessionEntity = new SessionEntity(_('Database error: Unable to verify user authentication. Please check your database configuration.'), 'danger');
            $this->authService->logout($sessionEntity);

            return 'sql'; // Return default to prevent further errors
        }
    }

    private function userUsesLDAP(): bool
    {
        return $this->getUserAuthMethod() === UserProvisioningService::AUTH_METHOD_LDAP;
    }
}
