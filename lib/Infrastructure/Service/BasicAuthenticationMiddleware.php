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
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\SqlAuthenticator;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Application\Service\UserEventLogger;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Model\UserEntity;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LdapUserEventLogger;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\NullLogHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic Authentication Middleware
 *
 * This middleware processes HTTP Basic Authentication credentials and authenticates the user
 *
 * @package Poweradmin\Infrastructure\Service
 */
class BasicAuthenticationMiddleware
{
    private PDOCommon $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private SqlAuthenticator $sqlAuthenticator;
    private ?LdapAuthenticator $ldapAuthenticator;

    /**
     * Constructor
     *
     * @param PDOCommon $db Database connection
     * @param ConfigurationManager $config Configuration manager
     */
    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();

        // Initialize authenticators
        $authService = new UserAuthenticationService(
            $this->config->get('security', 'password_encryption', 'bcrypt'),
            $this->config->get('security', 'password_cost', 10)
        );

        // Create minimal dependencies required for authenticators
        $userEventLogger = new UserEventLogger($db);
        $csrfTokenService = new CsrfTokenService();

        // Create a simple NullLogHandler and Logger
        $logHandler = new NullLogHandler();
        $logger = new Logger($logHandler, 'info');

        $loginAttemptService = new LoginAttemptService($db, $this->config);

        // Initialize SQL authenticator with all required dependencies
        $this->sqlAuthenticator = new SqlAuthenticator(
            $db,
            $this->config,
            $userEventLogger,
            $authService,
            $csrfTokenService,
            $logger,
            $loginAttemptService
        );

        // Create LDAP authenticator only if LDAP is enabled
        if ($this->config->get('ldap', 'enabled', false)) {
            $ldapUserEventLogger = new LdapUserEventLogger($db);
            $userContextService = new UserContextService();
            $this->ldapAuthenticator = new LdapAuthenticator(
                $db,
                $this->config,
                $ldapUserEventLogger,
                $authService,
                $csrfTokenService,
                $logger,
                $loginAttemptService,
                $userContextService
            );
        } else {
            $this->ldapAuthenticator = null;
        }
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
        if (!$this->config->get('api', 'basic_auth_enabled', true)) {
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
     * Handle unauthenticated request by sending a 401 response with WWW-Authenticate header
     *
     * @return JsonResponse
     */
    public function handleUnauthenticated(): JsonResponse
    {
        $response = new JsonResponse([
            'error' => true,
            'message' => 'Authentication required',
            'code' => 'auth_required'
        ], Response::HTTP_UNAUTHORIZED);

        // Add WWW-Authenticate header for HTTP Basic Auth with realm from config
        $realm = $this->config->get('api', 'basic_auth_realm', 'Poweradmin API');
        $response->headers->set('WWW-Authenticate', 'Basic realm="' . $realm . '", charset="UTF-8"');

        return $response;
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
        $decoded = base64_decode($encoded);
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

    /**
     * Authenticate a user with username and password
     *
     * @param string $username The username
     * @param string $password The password
     * @return int User ID if authentication succeeded, 0 otherwise
     */
    private function authenticateAndGetUserId(string $username, string $password): int
    {
        // Check if user exists
        if (!UserEntity::exists($this->db, $username)) {
            return 0;
        }

        // Get user ID and auth method
        $query = $this->db->prepare("SELECT id, password, use_ldap FROM users WHERE username = :username AND active = 1");
        $query->execute(['username' => $username]);
        $user = $query->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return 0;
        }

        // Create User model
        $userModel = new User($user['id'], $user['password'], (bool) $user['use_ldap']);

        // Try LDAP authentication first if user is configured for LDAP
        if ($userModel->isLdapUser() && $this->ldapAuthenticator !== null) {
            if ($this->ldapAuthenticatorApiAuth($userModel->getId(), $username, $password)) {
                // Set session for compatibility with legacy code (DomainManager)
                $_SESSION['userid'] = $userModel->getId();
                $_SESSION['auth_used'] = 'basic_auth';
                return $userModel->getId();
            }
        }

        // Fall back to SQL authentication
        if ($this->sqlAuthenticatorApiAuth($userModel, $password)) {
            // Set session for compatibility with legacy code (DomainManager)
            $_SESSION['userid'] = $userModel->getId();
            $_SESSION['auth_used'] = 'basic_auth';
            return $userModel->getId();
        }

        return 0;
    }

    /**
     * Authenticate a user with the SQL authenticator for API access
     *
     * @param User $userModel The user model
     * @param string $password The password
     * @return bool True if authentication succeeded, false otherwise
     */
    private function sqlAuthenticatorApiAuth(User $userModel, string $password): bool
    {
        $passwordEncryption = $this->config->get('security', 'password_encryption', 'bcrypt');
        $passwordCost = $this->config->get('security', 'password_cost', 10);

        $authService = new UserAuthenticationService($passwordEncryption, $passwordCost);

        // Verify the password directly without going through the full authentication flow
        return $authService->verifyPassword($password, $userModel->getHashedPassword());
    }

    /**
     * Authenticate a user with the LDAP authenticator for API access
     *
     * @param int $userId The user ID
     * @param string $username The username
     * @param string $password The password
     * @return bool True if authentication succeeded, false otherwise
     */
    private function ldapAuthenticatorApiAuth(int $userId, string $username, string $password): bool
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

        // Search for the user
        $filter = $ldapSearchFilter
            ? "(&($ldapUserAttribute=$username)$ldapSearchFilter)"
            : "($ldapUserAttribute=$username)";

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
