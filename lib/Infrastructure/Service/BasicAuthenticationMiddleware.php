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
use Poweradmin\Application\Service\SqlAuthenticator;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Model\UserEntity;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
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
    private PDOLayer $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private SqlAuthenticator $sqlAuthenticator;
    private ?LdapAuthenticator $ldapAuthenticator;

    /**
     * Constructor
     *
     * @param PDOLayer $db Database connection
     * @param ConfigurationManager $config Configuration manager
     */
    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();

        // Initialize authenticators
        $authService = new UserAuthenticationService(
            $this->config->get('security', 'password_encryption'),
            $this->config->get('security', 'password_cost')
        );

        $this->sqlAuthenticator = new SqlAuthenticator($db, $authService);

        // Create LDAP authenticator only if LDAP is enabled
        if ($this->config->get('ldap', 'enabled', false)) {
            $this->ldapAuthenticator = new LdapAuthenticator(
                $this->config->get('ldap', 'uri'),
                $this->config->get('ldap', 'base_dn'),
                $this->config->get('ldap', 'bind_dn'),
                $this->config->get('ldap', 'bind_pass'),
                $this->config->get('ldap', 'search_filter'),
                $this->messageService
            );
        } else {
            $this->ldapAuthenticator = null;
        }
    }

    /**
     * Process the request and attempt HTTP Basic Authentication
     *
     * @param Request $request The HTTP request
     * @return bool True if authentication succeeded, false otherwise
     */
    public function process(Request $request): bool
    {
        // Check if basic auth is enabled
        if (!$this->config->get('api', 'basic_auth_enabled', true)) {
            return false;
        }

        // Extract credentials from the request
        $credentials = $this->extractCredentials($request);
        if (empty($credentials)) {
            return false;
        }

        // Try to authenticate with the credentials
        list($username, $password) = $credentials;
        return $this->authenticate($username, $password);
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
     * @return bool True if authentication succeeded, false otherwise
     */
    private function authenticate(string $username, string $password): bool
    {
        // Check if user exists
        if (!UserEntity::exists($this->db, $username)) {
            return false;
        }

        // Get user ID and auth method
        $query = $this->db->prepare("SELECT id, password, use_ldap FROM users WHERE username = :username AND active = 1");
        $query->execute(['username' => $username]);
        $user = $query->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        // Create User model
        $userModel = new User($user['id'], $user['password'], (bool) $user['use_ldap']);

        // Try LDAP authentication first if user is configured for LDAP
        if ($userModel->isLdapUser() && $this->ldapAuthenticator !== null) {
            $ldapAuth = $this->ldapAuthenticator->authenticate($username, $password);
            if ($ldapAuth) {
                $this->setSessionData($userModel->getId(), 'ldap');
                return true;
            }
        }

        // Fall back to SQL authentication
        if ($this->sqlAuthenticator->authenticate($userModel, $password)) {
            $this->setSessionData($userModel->getId(), 'sql');
            return true;
        }

        return false;
    }

    /**
     * Set session data for the authenticated user
     *
     * @param int $userId User ID
     * @param string $authMethod Authentication method used
     */
    private function setSessionData(int $userId, string $authMethod): void
    {
        $_SESSION['userid'] = $userId;
        $_SESSION['auth_used'] = $authMethod;
        $_SESSION['last_used'] = time();
    }
}
