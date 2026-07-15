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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\UserTimezoneService;
use Poweradmin\Domain\ValueObject\LdapUserInfo;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionClass;

class LdapAuthenticator extends LoggingService
{
    private PDO $db;
    private ConfigurationManager $configManager;
    private LdapUserEventLogger $ldapUserEventLogger;
    private AuthenticationService $authenticationService;
    private CsrfTokenService $csrfTokenService;
    private LoginAttemptService $loginAttemptService;
    private UserContextService $userContextService;
    private array $serverParams;
    private ?MfaService $mfaService = null;

    /** Database driver name, used to build the LDAP username-match predicate. */
    private string $dbType = '';

    public function __construct(
        PDO $connection,
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
        $this->dbType = (string)$connection->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Initialize MFA service
        $userMfaRepository = new DbUserMfaRepository($connection, $configManager);
        $mailService = new MailService($configManager);
        $this->mfaService = new MfaService(
            $userMfaRepository,
            $configManager,
            $mailService,
            null,
            UserTimezoneService::createDefault($connection, $configManager)
        );
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
            if (isset($_POST["authenticate"])) {
                $this->ldapUserEventLogger->logLockout();
            }
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
        $ldap_sync_user_info = (bool)$this->configManager->get('ldap', 'sync_user_info', false);
        $ldap_fullname_attribute = $this->configManager->get('ldap', 'fullname_attribute', 'displayName');
        $ldap_email_attribute = $this->configManager->get('ldap', 'email_attribute', 'mail');
        $ldap_auto_provision = (bool)$this->configManager->get('ldap', 'auto_provision', false);
        $ldap_groups_attribute = $this->configManager->get('ldap', 'groups_attribute', 'memberOf');
        $ldap_group_sync = $this->configManager->get('ldap', 'permission_template_mapping', []) !== []
            || $this->configManager->get('ldap', 'group_mapping', []) !== [];

        if (!$this->userContextService->hasSessionData(SessionKeys::USERLOGIN) || !$this->userContextService->hasSessionData(SessionKeys::USERPWD)) {
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
        if ($ldap_sync_user_info || $ldap_auto_provision) {
            $attributes = array_merge($attributes, array_filter([$ldap_fullname_attribute, $ldap_email_attribute]));
        }
        if (($ldap_group_sync || $ldap_auto_provision) && $ldap_groups_attribute !== '') {
            $attributes[] = $ldap_groups_attribute;
        }

        // Properly escape user input to prevent LDAP injection
        $escaped_userlogin = ldap_escape($this->userContextService->getLoggedInUsername(), '', LDAP_ESCAPE_FILTER);

        $filter = $ldap_search_filter
            ? "(&($ldap_user_attribute=$escaped_userlogin)$ldap_search_filter)"
            : "($ldap_user_attribute=$escaped_userlogin)";

        if ($ldap_debug) {
            echo "<div class=\"container\"><pre>";
            echo sprintf("LDAP search filter: %s\n", htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'));
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
        $session_pass = $passwordEncryptionService->decrypt($this->userContextService->getSessionData(SessionKeys::USERPWD));
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

        $userInfo = LdapUserInfo::fromLdapEntry($entries[0], $username, $ldap_fullname_attribute, $ldap_email_attribute, $ldap_groups_attribute);
        $provisioningService = new UserProvisioningService($this->db, $this->configManager, $this->logger);

        // Accent-exact match, so a look-alike username cannot resolve to another account.
        $match = DbCompat::accentSensitiveEquals($this->dbType, 'username', ':username');
        $stmt = $this->db->prepare("SELECT id, fullname, email FROM users WHERE $match AND active = 1 AND use_ldap = 1");
        $stmt->execute([
            'username' => $username
        ]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rowObj && $ldap_auto_provision && $provisioningService->provisionUser($userInfo, 'ldap')) {
            $stmt->execute(['username' => $username]);
            $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->logInfo('Auto-provisioned LDAP user {username}', ['username' => $username]);
        } elseif ($rowObj && ($ldap_sync_user_info || $ldap_group_sync)) {
            $provisioningService->syncExistingUser((int)$rowObj['id'], $userInfo);
            $rowObj['fullname'] = $userInfo->getDisplayName() ?: $rowObj['fullname'];
        }

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

        // Email for MFA delivery. Only trust the LDAP directory's mail when user-info
        // sync is on; otherwise the Poweradmin account email is authoritative so a
        // self-editable directory attribute can't redirect MFA codes. A just
        // auto-provisioned user already has users.email set from LDAP, so the DB
        // fallback covers them without trusting LDAP for existing accounts.
        $sessionEmail = ($ldap_sync_user_info ? $userInfo->getEmail() : '') ?: ($rowObj['email'] ?? '');

        if (!$this->userContextService->hasSessionData(SessionKeys::CSRF_TOKEN)) {
            $this->userContextService->setSessionData(SessionKeys::CSRF_TOKEN, $this->csrfTokenService->generateToken());
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
            $this->userContextService->setSessionData(SessionKeys::PENDING_USERID, $rowObj['id']);
            $this->userContextService->setSessionData(SessionKeys::PENDING_NAME, $rowObj['fullname']);
            $this->userContextService->setSessionData(SessionKeys::PENDING_EMAIL, $sessionEmail);
            $this->userContextService->setSessionData(SessionKeys::PENDING_AUTH_USED, 'ldap');

            // Use our centralized MFA session manager to set MFA required
            MfaSessionManager::setMfaRequired($rowObj['id']);

            if (isset($_POST['authenticate'])) {
                $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
                $this->ldapUserEventLogger->logSuccessAuth();

                // Log before redirect
                $this->logInfo('LdapAuthenticator: Redirecting to MFA verification page');

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
            $this->userContextService->setSessionData(SessionKeys::USERID, $rowObj['id']);
            $this->userContextService->setSessionData(SessionKeys::NAME, $rowObj['fullname']);
            $this->userContextService->setSessionData(SessionKeys::EMAIL, $sessionEmail);
            $this->userContextService->setSessionData(SessionKeys::AUTH_USED, 'ldap');
            $this->userContextService->setSessionData(SessionKeys::AUTHENTICATED, true);
            $this->userContextService->setSessionData(SessionKeys::MFA_REQUIRED, false);

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
        if (!$this->userContextService->hasSessionData(SessionKeys::USERID)) {
            $this->logDebug('User ID not set, cache check skipped');
            return false;
        }

        // CRITICAL: Check authenticated flag is strictly true (not just set)
        // This prevents MFA bypass: MfaSessionManager sets authenticated=false while pending MFA
        // We must reject cache if authenticated is false, null, or any non-true value
        $authenticatedValue = $this->userContextService->getSessionData(SessionKeys::AUTHENTICATED);
        if ($authenticatedValue !== true) {
            $this->logDebug('User not fully authenticated (authenticated={value}), cache check skipped', [
                'value' => var_export($authenticatedValue, true)
            ]);
            return false;
        }

        // Check if LDAP auth timestamp exists
        if (!$this->userContextService->hasSessionData(SessionKeys::LDAP_AUTH_TIMESTAMP)) {
            $this->logDebug('No LDAP auth timestamp found in session');
            return false;
        }

        // Check if login identity has changed (user trying to switch accounts)
        $currentUsername = $this->userContextService->getLoggedInUsername();
        $cachedUsername = $this->userContextService->getSessionData(SessionKeys::LDAP_AUTH_USERNAME);

        if ($cachedUsername && $currentUsername !== $cachedUsername) {
            $this->logWarning('Username changed since LDAP authentication, invalidating cache (old: {oldUser}, new: {newUser})', [
                'oldUser' => $cachedUsername,
                'newUser' => $currentUsername
            ]);
            $this->invalidateAuthenticationCache();
            return false;
        }

        $authTimestamp = $this->userContextService->getSessionData(SessionKeys::LDAP_AUTH_TIMESTAMP);
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
        $cachedIp = $this->userContextService->getSessionData(SessionKeys::LDAP_AUTH_IP);

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
            $this->userContextService->setSessionData(SessionKeys::LDAP_AUTH_TIMESTAMP, time());
            $this->userContextService->setSessionData(SessionKeys::LDAP_AUTH_IP, $ipAddress);
            $this->userContextService->setSessionData(SessionKeys::LDAP_AUTH_USERNAME, $username);
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
        $match = DbCompat::accentSensitiveEquals($this->dbType, 'username', ':username');
        $stmt = $this->db->prepare("SELECT id, fullname FROM users WHERE $match AND active = 1 AND use_ldap = 1");
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
        if ($this->userContextService->hasSessionData(SessionKeys::LDAP_AUTH_TIMESTAMP)) {
            $this->userContextService->unsetSessionData(SessionKeys::LDAP_AUTH_TIMESTAMP);
            $this->logDebug('LDAP authentication timestamp cleared from session');
        }

        if ($this->userContextService->hasSessionData(SessionKeys::LDAP_AUTH_IP)) {
            $this->userContextService->unsetSessionData(SessionKeys::LDAP_AUTH_IP);
            $this->logDebug('LDAP authentication IP cleared from session');
        }

        if ($this->userContextService->hasSessionData(SessionKeys::LDAP_AUTH_USERNAME)) {
            $this->userContextService->unsetSessionData(SessionKeys::LDAP_AUTH_USERNAME);
            $this->logDebug('LDAP authentication username cleared from session');
        }
    }
}
