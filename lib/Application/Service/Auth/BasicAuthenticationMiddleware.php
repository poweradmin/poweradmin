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
use Poweradmin\Application\Service\Backend\DnsBackendProviderFactory;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Infrastructure\Repository\DbLoginAttemptRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates API requests with HTTP Basic credentials against the users table.
 *
 * This middleware processes HTTP Basic Authentication credentials and authenticates the user
 */
class BasicAuthenticationMiddleware
{
    private PDO $db;
    private ConfigurationInterface $config;
    private LoginAttemptService $loginAttemptService;
    private ?UserRepositoryInterface $userRepository = null;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param ConfigurationInterface $config Configuration manager
     */
    public function __construct(PDO $db, ConfigurationInterface $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->loginAttemptService = new LoginAttemptService(new DbLoginAttemptRepository($db, $this->config), $this->config);
    }

    /**
     * Get authenticated user ID from Basic Auth credentials (stateless)
     *
     * @param Request $request The HTTP request
     * @return int User ID if authenticated, 0 otherwise
     */
    public function getAuthenticatedUserId(Request $request): int
    {
        // Check if basic auth is enabled
        if (!$this->config->get('api', 'basic_auth_enabled', false)) {
            return 0;
        }

        // Extract credentials from the request
        $credentials = $this->extractCredentials($request);
        if (empty($credentials)) {
            return 0;
        }

        // Try to authenticate with the credentials
        list($username, $password) = $credentials;
        return $this->authenticateAndGetUserId($username, $password);
    }

    /**
     * Extract HTTP Basic Auth credentials from the request
     *
     * @param Request $request The HTTP request
     * @return array|null Array with [username, password] if found, null otherwise
     */
    private function extractCredentials(Request $request): ?array
    {
        // Check for Authorization header with Basic auth
        $authHeader = $request->headers->get('Authorization');
        if (empty($authHeader) || strpos($authHeader, 'Basic ') !== 0) {
            return null;
        }

        // Decode the Authorization header
        $encoded = substr($authHeader, 6);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return null;
        }

        // Split into username and password
        $credentials = explode(':', $decoded, 2);
        if (count($credentials) !== 2) {
            return null;
        }

        return $credentials;
    }

    private function userRepository(): UserRepositoryInterface
    {
        return $this->userRepository ??= new DbUserRepository(
            $this->db,
            $this->config,
            DnsBackendProviderFactory::isApiBackend($this->config)
        );
    }

    /**
     * Authenticate a user with username and password
     *
     * @param string $username The username
     * @param string $password The password
     * @return int User ID if authentication succeeded, 0 otherwise
     */
    private function authenticateAndGetUserId(string $username, #[\SensitiveParameter] string $password): int
    {
        if ($this->userRepository()->findByUsername($username) === null) {
            return 0;
        }

        // Refuse brute-force guesses once account_lockout thresholds trip. Without
        // this, Basic Auth bypasses the throttling that the browser login already
        // enforces via SqlAuthenticator.
        $ipAddress = IpAddressRetriever::fromConfig($_SERVER, $this->config)->getClientIp() ?: '0.0.0.0';
        if ($this->loginAttemptService->isAccountLocked($username, $ipAddress)) {
            return 0;
        }

        // Get user ID and auth method
        $user = $this->userRepository()->findBasicAuthUser($username);

        if (!$user) {
            // Disabled account: still record so probing inactive users contributes to lockout.
            $this->loginAttemptService->recordAttempt($username, $ipAddress, false);
            return 0;
        }

        // Create User model
        $userModel = new User($user['id'], $user['password'], (bool) $user['use_ldap']);

        // Try LDAP authentication first if user is configured for LDAP
        if ($userModel->isLdapUser() && $this->config->get('ldap', 'enabled', false)) {
            if ($this->ldapAuthenticatorApiAuth($userModel->getId(), $username, $password)) {
                return $this->onAuthSuccess($userModel->getId());
            }
            // LDAP users should not fall back to SQL authentication
            $this->loginAttemptService->recordAttempt($username, $ipAddress, false);
            return 0;
        }

        // Fall back to SQL authentication for non-LDAP users
        if ($this->sqlAuthenticatorApiAuth($userModel, $password)) {
            return $this->onAuthSuccess($userModel->getId());
        }

        $this->loginAttemptService->recordAttempt($username, $ipAddress, false);
        return 0;
    }

    /**
     * Basic Auth is stateless and called on every request, so we deliberately do
     * not call recordAttempt(true) here: that would (with clear_attempts_on_success)
     * wipe an attacker's accumulating failures on every legitimate API call.
     * Window-based expiry on failures is sufficient.
     */
    private function onAuthSuccess(int $userId): int
    {
        // Set session for compatibility with legacy code (DomainManager)
        $_SESSION[SessionKeys::USERID] = $userId;
        $_SESSION[SessionKeys::AUTH_USED] = 'basic_auth';
        return $userId;
    }

    /**
     * Authenticate a user with the SQL authenticator for API access
     *
     * @param User $userModel The user model
     * @param string $password The password
     * @return bool True if authentication succeeded, false otherwise
     */
    private function sqlAuthenticatorApiAuth(User $userModel, #[\SensitiveParameter] string $password): bool
    {
        $hashedPassword = $userModel->getHashedPassword();

        // Provisioned users (LDAP/OIDC/SAML) have no local password hash. Verifying
        // against an empty hash would throw (unknown algorithm), surfacing a 500 and
        // skipping the caller's failed-attempt recording; treat it as a clean failure.
        if ($hashedPassword === '') {
            return false;
        }

        $authService = UserAuthenticationService::fromConfig($this->config);

        // Verify the password directly without going through the full authentication flow
        return $authService->verifyPassword($password, $hashedPassword);
    }

    /**
     * Authenticate a user with the LDAP authenticator for API access
     *
     * @param int $userId The user ID
     * @param string $username The username
     * @param string $password The password
     * @return bool True if authentication succeeded, false otherwise
     */
    private function ldapAuthenticatorApiAuth(int $userId, string $username, #[\SensitiveParameter] string $password): bool
    {
        // Get LDAP connection settings from config
        $ldapUri = $this->config->get('ldap', 'uri', '');
        $ldapBaseDn = $this->config->get('ldap', 'base_dn', '');
        $ldapBindDn = $this->config->get('ldap', 'bind_dn', '');
        $ldapBindPassword = $this->config->get('ldap', 'bind_password', '');
        $ldapSearchFilter = $this->config->get('ldap', 'search_filter', '');
        $ldapUserAttribute = $this->config->get('ldap', 'user_attribute', 'uid');
        $ldapProto = $this->config->get('ldap', 'protocol_version', 3);

        if (empty($ldapUri) || empty($ldapBaseDn)) {
            return false;
        }

        // Connect to LDAP server
        $ldapConn = @ldap_connect($ldapUri);
        if (!$ldapConn) {
            return false;
        }

        // Set LDAP options
        ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, $ldapProto);
        ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

        // Bind with admin credentials
        if (!@ldap_bind($ldapConn, $ldapBindDn, $ldapBindPassword)) {
            return false;
        }

        // Search for the user, escaping the username as the web login path does -
        // usernames are not character-restricted, so they can carry filter syntax.
        $escapedUsername = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $filter = $ldapSearchFilter
            ? "(&($ldapUserAttribute=$escapedUsername)$ldapSearchFilter)"
            : "($ldapUserAttribute=$escapedUsername)";

        $attributes = array($ldapUserAttribute, 'dn');
        $search = @ldap_search($ldapConn, $ldapBaseDn, $filter, $attributes);
        if (!$search) {
            return false;
        }

        // Check if we found exactly one user
        $entries = ldap_get_entries($ldapConn, $search);
        if ((int)$entries["count"] !== 1) {
            return false;
        }

        // Try to bind with the user's DN and password
        $userDn = $entries[0]["dn"];
        $authenticated = @ldap_bind($ldapConn, $userDn, $password);

        return $authenticated;
    }
}
