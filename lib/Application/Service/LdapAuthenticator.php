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
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionClass;

class LdapAuthenticator extends LoggingService
{
    private PDOCommon $db;
    private ConfigurationManager $configManager;
    private LdapUserEventLogger $ldapUserEventLogger;
    private AuthenticationService $authenticationService;
    private CsrfTokenService $csrfTokenService;
    private LoginAttemptService $loginAttemptService;
    private UserContextService $userContextService;
    private array $serverParams;
    private ?MfaService $mfaService = null;

    public function __construct(
        PDOCommon $connection,
        ConfigurationManager $configManager,
        LdapUserEventLogger $ldapUserEventLogger,
        AuthenticationService $authService,
        CsrfTokenService $csrfTokenService,
        Logger $logger,
        LoginAttemptService $loginAttemptService,
        UserContextService $userContextService,
        array $serverParams = []
    ) {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->db = $connection;
        $this->configManager = $configManager;
        $this->ldapUserEventLogger = $ldapUserEventLogger;
        $this->authenticationService = $authService;
        $this->csrfTokenService = $csrfTokenService;
        $this->loginAttemptService = $loginAttemptService;
        $this->userContextService = $userContextService;
        $this->serverParams = $serverParams ?: $_SERVER;

        // Initialize MFA service
        $userMfaRepository = new DbUserMfaRepository($connection, $configManager);
        $mailService = new MailService($configManager);
        $this->mfaService = new MfaService($userMfaRepository, $configManager, $mailService);
    }

    public function authenticate(): void
    {
        $this->logInfo('Starting LDAP authentication process.');

        // Get the client IP using the IpAddressRetriever
        $ipRetriever = new IpAddressRetriever($this->serverParams);
        $ipAddress = $ipRetriever->getClientIp() ?: '0.0.0.0';
        $username = $this->userContextService->getLoggedInUsername() ?? '';

        // Check if the account is locked
        if ($this->loginAttemptService->isAccountLocked($username, $ipAddress)) {
            $this->logWarning('Account is locked for LDAP user {username}', ['username' => $username]);
            $sessionEntity = new SessionEntity(_('Account is temporarily locked. Please try again later.'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        // Check if LDAP authentication is cached and still valid
        if ($this->isCachedAuthenticationValid()) {
            // Even with valid cache, we must verify the user is still active in the database
            // This ensures disabled/deleted users are logged out immediately, not after cache expiry
            if (!$this->validateUserActiveStatus($username)) {
                $this->logWarning('Cached LDAP user {username} is no longer active, invalidating cache', ['username' => $username]);
                $this->invalidateAuthenticationCache();
                $sessionEntity = new SessionEntity(_('LDAP Authentication failed!'), 'danger');
                $this->authenticationService->logout($sessionEntity);
                return;
            }

            $this->logInfo('Using cached LDAP authentication for user {username}', ['username' => $username]);
            return;
        }

        $session_key = $this->configManager->get('security', 'session_key', '');
        $ldap_uri = $this->configManager->get('ldap', 'uri', '');
        $ldap_basedn = $this->configManager->get('ldap', 'base_dn', '');
        $ldap_search_filter = $this->configManager->get('ldap', 'search_filter', '');
        $ldap_binddn = $this->configManager->get('ldap', 'bind_dn', '');
        $ldap_bindpw = $this->configManager->get('ldap', 'bind_password', '');
        $ldap_proto = $this->configManager->get('ldap', 'protocol_version', 3);
        $ldap_debug = $this->configManager->get('ldap', 'debug', false);
        $ldap_user_attribute = $this->configManager->get('ldap', 'user_attribute', 'uid');

        if (!$this->userContextService->hasSessionData("userlogin") || !$this->userContextService->hasSessionData("userpwd")) {
            $this->logWarning('Session variables userlogin or userpwd are not set.');
            $sessionEntity = new SessionEntity('', 'danger');
            $this->authenticationService->auth($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to missing session variables.');
            return;
        }

        if ($ldap_debug) {
            ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 7);
        }

        $ldapconn = ldap_connect($ldap_uri);
        if (!$ldapconn) {
            $this->logError('Failed to connect to LDAP server.');
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->logFailedReason('ldap_connect');
            }
            $sessionEntity = new SessionEntity(_('Failed to connect to LDAP server!'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to connection failure.');
            return;
        }

        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, $ldap_proto);
        ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);

        if (!(@ldap_bind($ldapconn, $ldap_binddn, $ldap_bindpw))) {
            $this->logError('Failed to bind to LDAP server.');

            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->logFailedReason('ldap_bind');
            }

            $sessionEntity = new SessionEntity(_('Failed to bind to LDAP server!'), 'danger');
            $this->authenticationService->logout($sessionEntity);

            $this->logInfo('LDAP authentication process ended due to bind failure.');
            return;
        }

        $attributes = array($ldap_user_attribute, 'dn');

        // Properly escape user input to prevent LDAP injection
        $escaped_userlogin = ldap_escape($this->userContextService->getLoggedInUsername(), '', LDAP_ESCAPE_FILTER);

        $filter = $ldap_search_filter
            ? "(&($ldap_user_attribute=$escaped_userlogin)$ldap_search_filter)"
            : "($ldap_user_attribute=$escaped_userlogin)";

        if ($ldap_debug) {
            echo "<div class=\"container\"><pre>";
            echo sprintf("LDAP search filter: %s\n", $filter);
            echo "</pre></div>";
        }

        $ldapsearch = @ldap_search($ldapconn, $ldap_basedn, $filter, $attributes);
        if (!$ldapsearch) {
            $this->logError('Failed to search LDAP.');
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->logFailedReason('ldap_search');
            }
            $sessionEntity = new SessionEntity(_('Failed to search LDAP.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to search failure.');
            return;
        }

        $entries = ldap_get_entries($ldapconn, $ldapsearch);
        $count = (int)$entries["count"];
        if ($count !== 1) {
            $this->logWarning('LDAP search did not return exactly one user. Count: {count}', ['count' => $count]);
            if (isset($_POST["authenticate"])) {
                if ($count === 0) {
                    $this->ldapUserEventLogger->logFailedAuth();
                } else {
                    $this->ldapUserEventLogger->logFailedDuplicateAuth();
                }
            }
            $sessionEntity = new SessionEntity(_('Failed to authenticate against LDAP.'), 'danger');
            $this->authenticationService->logout($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to incorrect user count.');
            return;
        }
        $user_dn = $entries[0]["dn"];

        $passwordEncryptionService = new PasswordEncryptionService($session_key);
        $session_pass = $passwordEncryptionService->decrypt($this->userContextService->getSessionData('userpwd'));
        if (!@ldap_bind($ldapconn, $user_dn, $session_pass)) {
            $this->logWarning('LDAP authentication failed for user {username}', ['username' => $username]);
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->logFailedIncorrectPass();
                $this->loginAttemptService->recordAttempt($username, $ipAddress, false);
            }
            $sessionEntity = new SessionEntity(_('LDAP Authentication failed!'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to incorrect password.');
            return;
        }

        $this->loginAttemptService->recordAttempt($username, $ipAddress, true);

        $stmt = $this->db->prepare("SELECT id, fullname FROM users WHERE username = :username AND active = 1 AND use_ldap = 1");
        $stmt->execute([
            'username' => $username
        ]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj) {
            $this->logWarning('No active LDAP user found with the provided username: {username}', ['username' => $username]);
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->logFailedUserInactive();
            }
            $sessionEntity = new SessionEntity(_('LDAP Authentication failed!'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            $this->logInfo('LDAP authentication process ended due to no active user found.');
            return;
        }

        session_regenerate_id(true);
        $this->logInfo('Session ID regenerated for user {username}', ['username' => $username]);

        if (!$this->userContextService->hasSessionData('csrf_token')) {
            $this->userContextService->setSessionData('csrf_token', $this->csrfTokenService->generateToken());
            $this->logInfo('CSRF token generated for user {username}', ['username' => $username]);
        }

        // Check if MFA is globally enabled
        $mfaGloballyEnabled = $this->configManager->get('security', 'mfa.enabled', false);

        // Check if MFA is enabled for this user
        $mfaRequired = $mfaGloballyEnabled && $this->mfaService->isMfaEnabled($rowObj['id']);

        if ($mfaRequired) {
            $this->logInfo('MFA is required for LDAP user {username}', ['username' => $username]);

            // Store user details temporarily for MFA verification - DO NOT set userid yet!
            // This prevents API requests from bypassing MFA by checking isAuthenticated()
            $this->userContextService->setSessionData('pending_userid', $rowObj['id']);
            $this->userContextService->setSessionData('pending_name', $rowObj['fullname']);
            $this->userContextService->setSessionData('pending_auth_used', 'ldap');

            // Use our centralized MFA session manager to set MFA required
            MfaSessionManager::setMfaRequired($rowObj['id']);

            if (isset($_POST['authenticate'])) {
                $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
                $this->ldapUserEventLogger->logSuccessAuth();

                // Log before redirect
                error_log("LdapAuthenticator: Redirecting to MFA verification page");

                // Clear any output buffers
                if (ob_get_level()) {
                    ob_end_clean();
                }

                // Build redirect URL with base_url_prefix support for subfolder deployments
                $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
                $redirectUrl = $baseUrlPrefix . '/mfa/verify';
                header("Location: $redirectUrl", true, 302);
                exit;
            }
        } else {
            // No MFA required, proceed with full authentication
            // NOW it's safe to set userid since MFA is not required
            $this->userContextService->setSessionData('userid', $rowObj['id']);
            $this->userContextService->setSessionData('name', $rowObj['fullname']);
            $this->userContextService->setSessionData('auth_used', 'ldap');
            $this->userContextService->setSessionData('authenticated', true);
            $this->userContextService->setSessionData('mfa_required', false);

            // Update LDAP authentication cache BEFORE redirect (so next page load uses cache)
            $this->updateAuthenticationCache($ipAddress);

            if (isset($_POST['authenticate'])) {
                $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
                $this->ldapUserEventLogger->logSuccessAuth();
                session_write_close();
                $this->authenticationService->redirectToIndex();
            }
        }

        $this->logInfo('LDAP authentication process completed successfully for user {username}', ['username' => $username]);
    }

    /**
     * Check if cached LDAP authentication is still valid
     *
     * @return bool True if cache is valid and authentication can be skipped
     */
    private function isCachedAuthenticationValid(): bool
    {
        $cacheTimeout = $this->configManager->get('ldap', 'session_cache_timeout', 300);

        // If cache timeout is 0, caching is disabled
        if ($cacheTimeout <= 0) {
            $this->logDebug('LDAP session caching is disabled (timeout = 0)');
            return false;
        }

        // Check if user is fully authenticated (not pending MFA)
        // Must check both userid exists AND authenticated flag is strictly true
        // hasSessionData() only checks isset(), which returns true even for false values
        if (!$this->userContextService->hasSessionData('userid')) {
            $this->logDebug('User ID not set, cache check skipped');
            return false;
        }

        // CRITICAL: Check authenticated flag is strictly true (not just set)
        // This prevents MFA bypass: MfaSessionManager sets authenticated=false while pending MFA
        // We must reject cache if authenticated is false, null, or any non-true value
        $authenticatedValue = $this->userContextService->getSessionData('authenticated');
        if ($authenticatedValue !== true) {
            $this->logDebug('User not fully authenticated (authenticated={value}), cache check skipped', [
                'value' => var_export($authenticatedValue, true)
            ]);
            return false;
        }

        // Check if LDAP auth timestamp exists
        if (!$this->userContextService->hasSessionData('ldap_auth_timestamp')) {
            $this->logDebug('No LDAP auth timestamp found in session');
            return false;
        }

        // Check if login identity has changed (user trying to switch accounts)
        $currentUsername = $this->userContextService->getLoggedInUsername();
        $cachedUsername = $this->userContextService->getSessionData('ldap_auth_username');

        if ($cachedUsername && $currentUsername !== $cachedUsername) {
            $this->logWarning('Username changed since LDAP authentication, invalidating cache (old: {oldUser}, new: {newUser})', [
                'oldUser' => $cachedUsername,
                'newUser' => $currentUsername
            ]);
            $this->invalidateAuthenticationCache();
            return false;
        }

        $authTimestamp = $this->userContextService->getSessionData('ldap_auth_timestamp');
        $currentTime = time();
        $timeSinceAuth = $currentTime - $authTimestamp;

        // Check if cache has expired
        if ($timeSinceAuth > $cacheTimeout) {
            $this->logDebug('LDAP authentication cache expired (age: {age}s, timeout: {timeout}s)', [
                'age' => $timeSinceAuth,
                'timeout' => $cacheTimeout
            ]);
            return false;
        }

        // Validate IP address hasn't changed (security measure)
        $ipRetriever = new IpAddressRetriever($this->serverParams);
        $currentIp = $ipRetriever->getClientIp() ?: '0.0.0.0';
        $cachedIp = $this->userContextService->getSessionData('ldap_auth_ip');

        if ($cachedIp && $cachedIp !== $currentIp) {
            $this->logWarning('IP address changed since LDAP authentication, invalidating cache (old: {oldIp}, new: {newIp})', [
                'oldIp' => $cachedIp,
                'newIp' => $currentIp
            ]);
            $this->invalidateAuthenticationCache();
            return false;
        }

        $this->logDebug('LDAP authentication cache is valid (age: {age}s, timeout: {timeout}s)', [
            'age' => $timeSinceAuth,
            'timeout' => $cacheTimeout
        ]);

        return true;
    }

    /**
     * Update LDAP authentication cache with current timestamp
     *
     * @param string $ipAddress Client IP address
     * @return void
     */
    private function updateAuthenticationCache(string $ipAddress): void
    {
        $cacheTimeout = $this->configManager->get('ldap', 'session_cache_timeout', 300);

        // Only update cache if caching is enabled
        if ($cacheTimeout > 0) {
            $username = $this->userContextService->getLoggedInUsername();
            $this->userContextService->setSessionData('ldap_auth_timestamp', time());
            $this->userContextService->setSessionData('ldap_auth_ip', $ipAddress);
            $this->userContextService->setSessionData('ldap_auth_username', $username);
            $this->logDebug('LDAP authentication cache updated for user {username} from IP {ip}', [
                'username' => $username,
                'ip' => $ipAddress
            ]);
        }
    }

    /**
     * Validate that the user is still active and has LDAP enabled in the database
     *
     * This check is critical for security: it ensures that users who are disabled,
     * deleted, or have use_ldap set to 0 are immediately logged out, even if their
     * LDAP authentication is still cached.
     *
     * @param string $username The username to validate
     * @return bool True if user is active and use_ldap=1, false otherwise
     */
    private function validateUserActiveStatus(string $username): bool
    {
        $stmt = $this->db->prepare("SELECT id, fullname FROM users WHERE username = :username AND active = 1 AND use_ldap = 1");
        $stmt->execute(['username' => $username]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj) {
            $this->logDebug('User {username} is not active or use_ldap is disabled', ['username' => $username]);
            return false;
        }

        return true;
    }

    /**
     * Invalidate LDAP authentication cache
     *
     * @return void
     */
    public function invalidateAuthenticationCache(): void
    {
        if ($this->userContextService->hasSessionData('ldap_auth_timestamp')) {
            $this->userContextService->unsetSessionData('ldap_auth_timestamp');
            $this->logDebug('LDAP authentication timestamp cleared from session');
        }

        if ($this->userContextService->hasSessionData('ldap_auth_ip')) {
            $this->userContextService->unsetSessionData('ldap_auth_ip');
            $this->logDebug('LDAP authentication IP cleared from session');
        }

        if ($this->userContextService->hasSessionData('ldap_auth_username')) {
            $this->userContextService->unsetSessionData('ldap_auth_username');
            $this->logDebug('LDAP authentication username cleared from session');
        }
    }
}
