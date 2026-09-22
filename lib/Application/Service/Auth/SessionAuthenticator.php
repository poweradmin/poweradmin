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

namespace Poweradmin\Application\Service\Auth;

use PDO;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Infrastructure\Session\FlashMessage;
use Poweradmin\Domain\Service\Auth\PasswordEncryptionService;
use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\MfaSessionManager;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Domain\Service\User\UserAgreementService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Logger\ClassContextLogger;
use Poweradmin\Infrastructure\Logger\Logger;
use Psr\Log\LoggerInterface;
use Poweradmin\Infrastructure\Repository\DbUserAgreementRepository;
use Poweradmin\Application\Service\Web\RedirectService;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Service\Web\AuditService;

/**
 * Per-request login pipeline: CSRF and reCAPTCHA on the form, session expiry, then the SQL or LDAP authenticator.
 */
final class SessionAuthenticator
{
    private LoggerInterface $logger;
    private AuthenticationService $authService;
    private PDO $db;
    private ConfigurationInterface $configManager;
    private CsrfTokenService $csrfTokenService;
    private ?LdapAuthenticator $ldapAuthenticator = null;
    private ?SqlAuthenticator $sqlAuthenticator = null;
    private LoginAttemptService $loginAttemptService;
    private RecaptchaService $recaptchaService;
    private RedirectService $redirectService;
    private ControllerServiceFactory $services;
    private Request $request;
    private SessionInterface $session;

    public function __construct(PDO $connection, ConfigurationInterface $configManager, Request $request, SessionInterface $session)
    {
        $this->request = $request;
        $this->session = $session;
        $this->logger = ClassContextLogger::for(Logger::fromConfig($configManager), self::class);

        $this->db = $connection;
        $this->configManager = $configManager;

        $this->services = new ControllerServiceFactory($connection, $configManager, $this->logger, new SessionActor($session), $session);
        $this->redirectService = $this->services->redirectService();
        $this->authService = $this->services->authenticationService();
        $this->csrfTokenService = new CsrfTokenService($session);

        $this->loginAttemptService = $this->services->loginAttemptService();
        $this->recaptchaService = new RecaptchaService($configManager);
    }

    private function auditService(): AuditService
    {
        return $this->services->auditService();
    }

    /**
     * Builds the LDAP authenticator on first use, so installations without LDAP
     * never construct it or its MFA graph.
     */
    private function ldapAuthenticator(): LdapAuthenticator
    {
        return $this->ldapAuthenticator ??= new LdapAuthenticator(
            $this->services->userRepository(),
            $this->configManager,
            $this->auditService(),
            $this->csrfTokenService,
            $this->logger,
            $this->loginAttemptService,
            new UserContextService($this->session),
            $this->services->clientContext(),
            $this->services->mfaService(),
            $this->services->userProvisioningService(),
            $this->session
        );
    }

    /**
     * Builds the SQL authenticator on first use, so LDAP, OIDC, and SAML sessions
     * skip it entirely.
     */
    private function sqlAuthenticator(): SqlAuthenticator
    {
        return $this->sqlAuthenticator ??= new SqlAuthenticator(
            $this->configManager,
            $this->auditService(),
            $this->csrfTokenService,
            $this->logger,
            $this->loginAttemptService,
            $this->services->clientContext(),
            $this->services->mfaService(),
            $this->services->userRepository(),
            $this->session
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
        $this->logger->debug('Starting authentication process');

        $iface_expire = $this->configManager->get('interface', 'session_timeout', 1800);
        $session_key = $this->configManager->get('security', 'session_key', '');
        $ldap_use = $this->configManager->get('ldap', 'enabled', false);
        $login_token_validation = $this->configManager->get('security', 'login_token_validation', true);
        $global_token_validation = $this->configManager->get('security', 'global_token_validation', true);

        // Logout is now handled by LogoutController via /logout route

        $isLogin = $this->request->getPostParam('authenticate') !== null;
        $credentials = $isLogin ? $this->postedCredentials() : null;
        $postedUsername = $credentials !== null ? $credentials->username : 'unknown';

        // A posted _token[] arrives as an array, which validateToken() cannot accept
        $login_token = $this->request->getPostParam('_token');
        if (
            ($login_token_validation || $global_token_validation)
            && $isLogin
            && !$this->csrfTokenService->validateToken(is_string($login_token) ? $login_token : '', SessionKeys::LOGIN_TOKEN)
        ) {
            $this->logger->warning('Invalid CSRF token for user {username}', ['username' => $postedUsername]);

            $sessionEntity = new FlashMessage(_('Invalid CSRF token.'), 'danger');
            $this->authService->auth($sessionEntity);

            $this->logger->debug('CSRF token validation failed for user {username}', ['username' => $postedUsername]);
            return;
        }

        // If a user had just entered his/her login && password, store them in our session.
        if ($credentials !== null) {
            $this->logger->debug('User {username} attempting to authenticate', ['username' => $postedUsername]);

            // Verify reCAPTCHA if enabled
            if ($this->recaptchaService->isEnabled()) {
                $remoteIp = $this->services->clientContext()->ip;

                if (!$this->recaptchaService->verify($credentials->recaptchaResponse, $remoteIp)) {
                    $this->logger->warning('reCAPTCHA verification failed for user {username}', ['username' => $postedUsername]);

                    $sessionEntity = new FlashMessage(_('reCAPTCHA verification failed. Please try again.'), 'danger');
                    $this->authService->auth($sessionEntity);

                    $this->logger->debug('Authentication blocked due to reCAPTCHA failure for user {username}', ['username' => $postedUsername]);
                    return;
                }
            }

            if ($credentials->password !== '') {
                $passwordEncryptionService = new PasswordEncryptionService($session_key);
                $this->session->set(SessionKeys::USERPWD, $passwordEncryptionService->encrypt($credentials->password));
                $this->logger->debug('Password encrypted for user {username}', ['username' => $credentials->username]);

                $this->session->set(SessionKeys::USERLOGIN, $credentials->username);
                $this->logger->debug('User login set for user {username}', ['username' => $credentials->username]);

                $this->session->set(SessionKeys::USERLANG, $credentials->userlang ?? $this->configManager->get('interface', 'language', 'en_EN'));
                $this->logger->debug('User language set for user {username}', ['username' => $credentials->username]);

                $this->logger->info('User {username} authenticated', ['username' => $credentials->username]);
            } else {
                $this->logger->error('Empty password attempt for user {username}', ['username' => $postedUsername]);

                $sessionEntity = new FlashMessage(_('An empty password is not allowed'), 'danger');
                $this->authService->auth($sessionEntity);

                $this->logger->debug('Authentication failed due to empty password for user {username}', ['username' => $postedUsername]);
                return;
            }
        }

        // Check if the session hasn't expired yet.
        if ($this->session->has(SessionKeys::USERID) && $this->session->has(SessionKeys::LASTMOD) && $this->session->get(SessionKeys::LASTMOD) !== "" && ((time() - $this->session->get(SessionKeys::LASTMOD)) > $iface_expire)) {
            $this->logger->info('Session expired for user {userid}', ['userid' => $this->session->get(SessionKeys::USERID)]);

            $this->auditService()->logSessionExpired();

            $sessionEntity = new FlashMessage(_('Session expired, please login again.'), 'danger');
            $this->authService->logout($sessionEntity);

            $this->logger->debug('Session expired and user {userid} logged out', ['userid' => $this->session->get(SessionKeys::USERID)]);
            return;
        }

        // If the session hasn't expired yet, give our session a fresh new timestamp.
        $this->session->set(SessionKeys::LASTMOD, time());
        $this->logger->debug('Session timestamp updated for user {username}', ['username' => $this->session->get(SessionKeys::USERLOGIN, 'unknown')]);

        $authMethod = $this->getUserAuthMethod();

        switch ($authMethod) {
            case UserProvisioningService::AUTH_METHOD_OIDC:
                $this->logger->info('User {username} uses OIDC for authentication - skipping password verification', ['username' => $this->session->get(SessionKeys::USERLOGIN, 'unknown')]);
                // OIDC users are already authenticated, no need to verify password
                break;
            case UserProvisioningService::AUTH_METHOD_SAML:
                $this->logger->info('User {username} uses SAML for authentication - skipping password verification', ['username' => $this->session->get(SessionKeys::USERLOGIN, 'unknown')]);
                // SAML users are already authenticated, no need to verify password
                break;
            case UserProvisioningService::AUTH_METHOD_LDAP:
                if ($ldap_use) {
                    $this->logger->info('User {username} uses LDAP for authentication', ['username' => $this->session->get(SessionKeys::USERLOGIN)]);
                    $this->completeLogin($this->ldapAuthenticator()->authenticate($credentials));
                } else {
                    $this->logger->warning('User {username} configured for LDAP but LDAP is disabled', ['username' => $this->session->get(SessionKeys::USERLOGIN)]);
                    $sessionEntity = new FlashMessage(_('LDAP authentication is disabled'), 'danger');
                    $this->authService->logout($sessionEntity);
                }
                break;
            case 'sql':
            default:
                if ($this->session->has(SessionKeys::USERLOGIN)) {
                    $this->logger->info('User {username} uses SQL for authentication', ['username' => $this->session->get(SessionKeys::USERLOGIN)]);
                }
                $this->completeLogin($this->sqlAuthenticator()->authenticate($credentials));
                break;
        }

        // Check for user agreement requirements after successful authentication
        $this->checkUserAgreementRequirements();

        // Check for MFA enforcement requirements after user agreement
        $this->checkMfaEnforcementRequirements();

        $this->checkPendingMfaVerification();

        $this->logger->debug('Authentication process completed for user {username}', ['username' => $this->session->get(SessionKeys::USERLOGIN, 'unknown')]);
    }

    private function postedCredentials(): LoginCredentials
    {
        $username = $this->request->getPostParam('username');
        $password = $this->request->getPostParam('password');
        $userlang = $this->request->getPostParam('userlang');
        $recaptcha = $this->request->getPostParam('g-recaptcha-response');

        return new LoginCredentials(
            is_string($username) ? $username : '',
            is_string($password) ? $password : '',
            is_string($userlang) ? $userlang : null,
            is_string($recaptcha) ? $recaptcha : ''
        );
    }

    /**
     * Turns the authenticator's decision into the response: a failure flashes its
     * message on the login page, a completed login leaves for the outcome's path.
     */
    private function completeLogin(AuthOutcome $outcome): void
    {
        if ($outcome->isFailure()) {
            $sessionEntity = new FlashMessage($outcome->message, 'danger');
            if ($outcome->endSession) {
                $this->authService->logout($sessionEntity);
            } else {
                $this->authService->auth($sessionEntity);
            }
            return;
        }

        if ($outcome->redirectPath === null) {
            return;
        }

        // Nothing buffered so far belongs in a redirect response
        if (ob_get_level()) {
            ob_end_clean();
        }
        $this->session->writeClose();

        $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
        $this->redirectService->redirectTo($baseUrlPrefix . $outcome->redirectPath);
    }

    /**
     * A session that still owes its second factor may only reach the verification
     * form. API requests get a JSON 403 instead of a redirect they cannot follow.
     */
    private function checkPendingMfaVerification(): void
    {
        if (
            !(new UserContextService($this->session))->isAuthenticated()
            || !(new MfaSessionManager($this->session, $this->logger))->isMfaRequired()
            || $this->getCurrentRequestPath() === '/mfa/verify'
        ) {
            return;
        }

        if (RequestContext::isApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => true, 'message' => 'Multi-factor authentication required']);
            throw new RequestHalted(RequestHalted::KIND_RESPONSE, '403');
        }

        // Ensure session is written before redirecting
        $this->session->writeClose();

        $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
        $this->redirectService->redirectTo($baseUrlPrefix . '/mfa/verify');
    }

    private function checkUserAgreementRequirements(): void
    {
        $userContextService = new UserContextService($this->session);

        // Only check if user is authenticated and not in API context
        if (!$userContextService->isAuthenticated()) {
            return;
        }

        // Get the current request path (without base_url_prefix)
        $currentPath = $this->getCurrentRequestPath();

        // Skip agreement check for API requests and specific paths
        $skipPaths = ['/user-agreement', '/logout', '/mfa/verify', '/mfa/setup'];
        if ($this->isPathInList($currentPath, $skipPaths) || str_contains($currentPath, '/api/')) {
            return;
        }

        $agreementService = new UserAgreementService(
            new DbUserAgreementRepository($this->db, $this->configManager),
            $this->configManager
        );

        $userId = $userContextService->getLoggedInUserId();
        if ($userId && $agreementService->isAgreementRequired($userId)) {
            $this->logger->info('User agreement required for user {userid}', ['userid' => $userId]);

            // Redirect to agreement page - user will be sent to index after acceptance
            $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
            $this->redirectService->redirectTo($baseUrlPrefix . '/user-agreement');
        }
    }

    private function checkMfaEnforcementRequirements(): void
    {
        // isMfaSetupRequired() short-circuits on this same flag, so returning here is
        // equivalent and keeps the service graph and the user lookup off every request
        if (!$this->configManager->get('security', 'mfa.enabled', false)) {
            return;
        }

        $userContextService = new UserContextService($this->session);

        // Only check if user is authenticated
        if (!$userContextService->isAuthenticated()) {
            return;
        }

        // Get the current request path (without base_url_prefix)
        $currentPath = $this->getCurrentRequestPath();

        // Skip MFA enforcement check for specific paths and API requests
        $skipPaths = ['/logout', '/mfa/verify', '/mfa/setup'];
        if ($this->isPathInList($currentPath, $skipPaths) || str_contains($currentPath, '/api/')) {
            return;
        }

        $userId = $userContextService->getLoggedInUserId();
        if (!$userId) {
            return;
        }

        // Create MFA service to check enforcement
        $mfaService = $this->services->mfaService();

        // Check if MFA setup is required for this user
        if ($mfaService->isMfaSetupRequired($userId, $this->db, $userContextService->getAuthMethod())) {
            $this->logger->info('MFA setup required for user {userid}', ['userid' => $userId]);

            // Set a session flag to indicate this is an enforced setup
            $this->session->set(AuthFlowSessionKeys::MFA_SETUP_ENFORCED, true);

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
     * Check if the current path matches any path in the skip list.
     *
     * @param string $currentPath The current request path
     * @param array $skipPaths List of paths to skip
     * @return bool True if path should be skipped
     */
    private function isPathInList(string $currentPath, array $skipPaths): bool
    {
        foreach ($skipPaths as $skipPath) {
            if ($currentPath === $skipPath || str_starts_with($currentPath, $skipPath . '/')) {
                return true;
            }
        }
        return false;
    }

    private function getUserAuthMethod(): string
    {
        if (!$this->session->has(SessionKeys::USERLOGIN)) {
            $this->logger->debug('No user login found in session');
            return 'sql'; // Default to SQL if no user logged in
        }

        // First check how the current session was created
        if ($this->session->has(SessionKeys::AUTH_METHOD_USED)) {
            $sessionAuthMethod = $this->session->get(SessionKeys::AUTH_METHOD_USED);
            $this->logger->debug('Using session auth method for user {username}: {authMethod}', [
                'username' => $this->session->get(SessionKeys::USERLOGIN),
                'authMethod' => $sessionAuthMethod
            ]);
            return $sessionAuthMethod;
        }

        // Fall back to database auth_method (for existing SQL/LDAP sessions)
        try {
            $rowObj = $this->services->userRepository()->findAuthMethodRow($this->session->get(SessionKeys::USERLOGIN));

            if ($rowObj === null) {
                $this->logger->warning('User {username} not found in database', ['username' => $this->session->get(SessionKeys::USERLOGIN)]);
                return 'sql'; // Default to SQL if user not found
            }

            $authMethod = $rowObj['auth_method'] ?? 'sql';
            $this->logger->debug('Using database auth method for user {username}: {authMethod}', [
                'username' => $this->session->get(SessionKeys::USERLOGIN),
                'authMethod' => $authMethod
            ]);

            return $authMethod;
        } catch (\PDOException $e) {
            $this->logger->error('Database error while fetching auth method for user {username}: {error}', [
                'username' => $this->session->get(SessionKeys::USERLOGIN),
                'error' => $e->getMessage()
            ]);

            // Log out user and display error message
            $sessionEntity = new FlashMessage(_('Database error: Unable to verify user authentication. Please check your database configuration.'), 'danger');
            $this->authService->logout($sessionEntity);

            return 'sql'; // Return default to prevent further errors
        }
    }
}
