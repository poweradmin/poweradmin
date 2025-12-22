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

            if (isset($_POST['authenticate'])) {
                $this->loginAttemptService->recordAttempt($username, $ipAddress, true);
                $this->ldapUserEventLogger->logSuccessAuth();
                session_write_close();
                $this->authenticationService->redirectToIndex();
            }
        }

        $this->logInfo('LDAP authentication process completed successfully for user {username}', ['username' => $username]);
    }
}
