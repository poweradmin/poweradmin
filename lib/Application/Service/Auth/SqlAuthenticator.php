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

use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Infrastructure\Session\MfaSessionManager;
use Poweradmin\Domain\Service\Auth\PasswordEncryptionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\ClassContextLogger;
use Psr\Log\LoggerInterface;
use Poweradmin\Application\Service\Web\AuditService;

/**
 * Session login against the users table with lockout checks, hash upgrades and MFA hand-off.
 */
final class SqlAuthenticator
{
    private LoggerInterface $logger;
    private ConfigurationInterface $configManager;
    private AuditService $auditService;
    private CsrfTokenService $csrfTokenService;
    private LoginAttemptService $loginAttemptService;
    private ClientContext $client;
    private MfaService $mfaService;
    private UserRepositoryInterface $userRepository;

    public function __construct(
        ConfigurationInterface $configManager,
        AuditService $auditService,
        CsrfTokenService $csrfTokenService,
        LoggerInterface $logger,
        LoginAttemptService $loginAttemptService,
        ClientContext $client,
        MfaService $mfaService,
        UserRepositoryInterface $userRepository
    ) {
        $this->logger = ClassContextLogger::for($logger, self::class);

        $this->configManager = $configManager;
        $this->auditService = $auditService;
        $this->csrfTokenService = $csrfTokenService;
        $this->loginAttemptService = $loginAttemptService;
        $this->client = $client;
        $this->mfaService = $mfaService;
        $this->userRepository = $userRepository;
    }

    /**
     * Verifies the session's stored credentials, or the just-posted $credentials on
     * the login request, and records the result in the session. The caller turns the
     * outcome into a redirect; nothing here writes to the response.
     */
    public function authenticate(?LoginCredentials $credentials = null): AuthOutcome
    {
        $this->logger->info('Starting authentication process.');

        $isLogin = $credentials !== null;
        $ipAddress = $this->client->ip ?: '0.0.0.0';
        $username = $credentials !== null ? $credentials->username : ($_SESSION[SessionKeys::USERLOGIN] ?? '');

        if ($this->loginAttemptService->isAccountLocked($username, $ipAddress)) {
            $this->logger->warning('Account is locked for user {username}', ['username' => $username]);
            if ($isLogin) {
                $this->auditService->logLoginLocked(AuthMethod::SQL);
            }
            return AuthOutcome::failure(_('Account is temporarily locked. Please try again later.'));
        }

        $sessionKey = $this->configManager->get('security', 'session_key');

        if (!isset($_SESSION[SessionKeys::USERLOGIN]) || !isset($_SESSION[SessionKeys::USERPWD])) {
            $this->logger->warning('Session variables userlogin or userpwd are not set.');
            $this->logger->info('Authentication process ended due to missing session variables.');
            return AuthOutcome::failure('');
        }

        $encryptionService = new PasswordEncryptionService($sessionKey);
        $sessionPassword = $credentials !== null ? $credentials->password : $encryptionService->decrypt($_SESSION[SessionKeys::USERPWD]);

        $userAuthService = UserAuthenticationService::fromConfig($this->configManager);

        $rowObj = $this->userRepository->findSqlLoginUser($username);

        if (!$rowObj) {
            // A missing user answered in ~6ms where a real one took ~212ms, telling
            // an unauthenticated caller which accounts exist. Lockout ships disabled.
            $userAuthService->verifyPassword($sessionPassword, $userAuthService->dummyVerificationHash());

            $this->logger->warning('No user found with the provided username: {username}', ['username' => $username]);
            $this->logger->info('Authentication process ended due to no user found.');
            return $this->failedAuthentication($isLogin, LoginFailureReason::NO_SUCH_USER);
        }

        $storedHash = (string)($rowObj['password'] ?? '');

        // Rows with no modern hash (LDAP/OIDC/SAML, or a legacy md5) verify instantly,
        // which would mark those usernames as existing now the missing-user path is slow.
        if (password_get_info($storedHash)['algo'] === null) {
            $userAuthService->verifyPassword($sessionPassword, $userAuthService->dummyVerificationHash());
        }

        if (!$userAuthService->verifyPassword($sessionPassword, $storedHash)) {
            $this->logger->warning('Password verification failed for user {username}', ['username' => $username]);
            $this->loginAttemptService->recordAttempt($username, $ipAddress, false);
            $this->logger->info('Authentication process ended due to password verification failure.');
            return $this->failedAuthentication($isLogin, LoginFailureReason::WRONG_PASSWORD);
        }

        if ($rowObj['active'] != 1) {
            $this->logger->warning('User account is disabled for user {username}', ['username' => $username]);
            if ($isLogin) {
                $this->auditService->logLoginFailed(AuthMethod::SQL, LoginFailureReason::ACCOUNT_DISABLED);
            }
            $this->logger->info('Authentication process ended due to disabled user account.');
            return AuthOutcome::failure(_('The user account is disabled.'));
        }

        if ($userAuthService->requiresRehash($rowObj['password'])) {
            $this->logger->info('Password requires rehashing for user {username}', ['username' => $username]);
            $this->userRepository->updatePassword((int)$rowObj["id"], $userAuthService->hashPassword($sessionPassword));
        }

        // Regenerate the session id only at actual login (credentials just posted),
        // not on every authenticated request. SQL auth runs on every request (no
        // auth cache like LDAP), so regenerating each time destroyed the previous id
        // and bounced overlapping requests to login. Login-time regeneration still
        // protects against session fixation.
        if ($isLogin && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $this->logger->info('Session ID regenerated for user {username}', ['username' => $username]);
        }

        if ($isLogin) {
            $this->csrfTokenService->regenerateToken();
        } else {
            $this->csrfTokenService->ensureTokenExists();
        }
        $this->logger->info('CSRF token ensured for user {username}', ['username' => $username]);

        // Check if MFA is globally enabled
        $mfaGloballyEnabled = $this->configManager->get('security', 'mfa.enabled', false);

        // Check if MFA is enabled for this user
        $mfaRequired = $mfaGloballyEnabled && $this->mfaService->isMfaEnabled($rowObj['id']);

        if ($mfaRequired) {
            $this->logger->info('MFA is required for user {username}', ['username' => $username]);

            // Store user details temporarily for MFA verification - DO NOT set userid yet!
            $_SESSION[SessionKeys::PENDING_USERID] = $rowObj['id'];
            $_SESSION[SessionKeys::PENDING_NAME] = $rowObj['fullname'];
            $_SESSION[SessionKeys::PENDING_EMAIL] = $rowObj['email'];
            $_SESSION[SessionKeys::PENDING_AUTH_USED] = 'internal';

            // Use our centralized MFA session manager to set MFA required
            MfaSessionManager::setMfaRequired($rowObj['id']);

            if (!$isLogin) {
                return AuthOutcome::mfaRequired();
            }

            $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
            $this->auditService->logLoginSuccess(AuthMethod::SQL);
            $this->logger->info('SqlAuthenticator: Redirecting to MFA verification page');
            return AuthOutcome::mfaRequired('/mfa/verify');
        }

        // No MFA required, proceed with full authentication
        // NOW it's safe to set userid since MFA is not required
        $_SESSION[SessionKeys::USERID] = $rowObj['id'];
        $_SESSION[SessionKeys::NAME] = $rowObj['fullname'];
        $_SESSION[SessionKeys::EMAIL] = $rowObj['email'];
        $_SESSION[SessionKeys::AUTH_USED] = 'internal';
        $_SESSION[SessionKeys::AUTHENTICATED] = true;
        MfaSessionManager::setMfaNotRequired();

        $this->logger->info('Authentication process completed successfully for user {username}', ['username' => $username]);

        if (!$isLogin) {
            return AuthOutcome::success();
        }

        $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
        $this->auditService->logLoginSuccess(AuthMethod::SQL);
        return AuthOutcome::success('/');
    }

    private function failedAuthentication(bool $isLogin, LoginFailureReason $reason): AuthOutcome
    {
        $this->logger->info('Handling failed authentication.');

        if ($isLogin) {
            $this->auditService->logLoginFailed(AuthMethod::SQL, $reason);
            return AuthOutcome::failure(_('Authentication failed!'));
        }

        unset($_SESSION[SessionKeys::USERPWD]);
        unset($_SESSION[SessionKeys::USERLOGIN]);
        return AuthOutcome::failure(_('Session expired, please login again.'));
    }
}
