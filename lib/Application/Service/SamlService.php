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

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Domain\ValueObject\SamlUserInfo;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Service\RedirectService;
use ReflectionClass;

class SamlService extends LoggingService
{
    private ConfigurationManager $configManager;
    private AuthenticationService $authenticationService;
    private SessionService $sessionService;
    private SamlConfigurationService $samlConfigurationService;
    private UserProvisioningService $userProvisioningService;
    private Request $request;
    private CsrfTokenService $csrfTokenService;
    private PDOCommon $db;
    private ?MfaService $mfaService = null;

    public function __construct(
        ConfigurationManager $configManager,
        SamlConfigurationService $samlConfigurationService,
        UserProvisioningService $userProvisioningService,
        Logger $logger,
        PDOCommon $db,
        ?Request $request = null
    ) {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->configManager = $configManager;
        $this->samlConfigurationService = $samlConfigurationService;
        $this->userProvisioningService = $userProvisioningService;
        $this->request = $request ?: new Request();
        $this->db = $db;

        // Initialize services following existing patterns
        $this->sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authenticationService = new AuthenticationService($this->sessionService, $redirectService);
        $this->csrfTokenService = new CsrfTokenService();

        // Initialize MFA service
        $userMfaRepository = new DbUserMfaRepository($db, $configManager);
        $mailService = new MailService($configManager);
        $this->mfaService = new MfaService($userMfaRepository, $configManager, $mailService);
    }

    public function isEnabled(): bool
    {
        return $this->configManager->get('saml', 'enabled', false);
    }

    public function getAvailableProviders(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        // Validate SAML configuration first
        $configErrors = $this->samlConfigurationService->validatePermissionTemplateMapping();
        if (!empty($configErrors)) {
            $this->logError('Configuration validation failed: {errors}', ['errors' => implode(', ', $configErrors)]);
            return [];
        }

        $providers = $this->configManager->get('saml', 'providers', []);
        $availableProviders = [];

        foreach ($providers as $providerId => $config) {
            if ($this->isProviderEnabled($providerId, $config)) {
                $availableProviders[$providerId] = [
                    'id' => $providerId,
                    'name' => $config['name'] ?? ucfirst($providerId),
                    'display_name' => $config['display_name'] ?? $config['name'] ?? ucfirst($providerId),
                ];
            }
        }

        return $availableProviders;
    }

    public function initiateAuthFlow(string $providerId): string
    {
        try {
            $this->logInfo('Initiating SAML auth flow for provider: {provider}', ['provider' => $providerId]);

            // Validate configuration before starting flow
            $configErrors = $this->samlConfigurationService->validatePermissionTemplateMapping();
            if (!empty($configErrors)) {
                $this->logError('Cannot initiate SAML flow - configuration errors: {errors}', [
                    'errors' => implode(', ', $configErrors)
                ]);
                throw new \RuntimeException('Configuration validation failed: ' . implode(', ', $configErrors));
            }

            $auth = $this->createAuth($providerId);
            if (!$auth) {
                $this->logError('Failed to create SAML auth for provider: {provider}', ['provider' => $providerId]);
                throw new \RuntimeException("Provider {$providerId} not found or not configured");
            }

            // Store provider ID for callback processing
            $this->setSessionValue('saml_provider', $providerId);
            $this->logInfo('Stored provider ID in session: {provider_id}, Session ID: {session_id}', [
                'provider_id' => $providerId,
                'session_id' => session_id()
            ]);

            // Use RelayState to maintain provider ID across SAML flow
            $relayState = base64_encode(json_encode(['provider' => $providerId]));

            // Initiate SSO and get the redirect URL (stay=true prevents immediate redirect and returns URL)
            $redirectUrl = $auth->login($relayState, [], false, false, true);

            $this->logInfo('Generated SAML SSO URL: {url}', ['url' => $redirectUrl]);

            return $redirectUrl;
        } catch (\Exception $e) {
            $this->logError('Error initiating SAML auth flow for {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function handleAssertion(): void
    {
        $this->logInfo('Processing SAML assertion');

        $providerId = $this->getSessionValue('saml_provider', '');

        // If provider ID not in session, try to get it from RelayState
        if (empty($providerId) && !empty($_POST['RelayState'])) {
            try {
                $relayState = json_decode(base64_decode($_POST['RelayState']), true);
                if (isset($relayState['provider'])) {
                    $providerId = $relayState['provider'];
                    $this->logInfo('Retrieved provider ID from RelayState: {provider}', ['provider' => $providerId]);
                }
            } catch (\Exception $e) {
                $this->logWarning('Failed to decode RelayState: {error}', ['error' => $e->getMessage()]);
            }
        }

        if (empty($providerId)) {
            $this->logWarning('No provider ID in session or RelayState during SAML assertion. Session ID: {session_id}', [
                'session_id' => session_id()
            ]);
            $sessionEntity = new SessionEntity(_('Authentication failed: Invalid session'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        try {
            // Temporarily set environment for reverse proxy detection during SAML processing
            $originalHttps = $_SERVER['HTTPS'] ?? null;
            $originalPort = $_SERVER['SERVER_PORT'] ?? null;

            // Detect if we're behind a reverse proxy (like ngrok) and need HTTPS detection help
            if ($this->isReverseProxyEnvironment()) {
                $_SERVER['HTTPS'] = 'on';
                $_SERVER['SERVER_PORT'] = '443';
            }

            $auth = $this->createAuth($providerId);
            if (!$auth) {
                // Restore original values before throwing
                if ($originalHttps !== null) {
                    $_SERVER['HTTPS'] = $originalHttps;
                } else {
                    unset($_SERVER['HTTPS']);
                }
                if ($originalPort !== null) {
                    $_SERVER['SERVER_PORT'] = $originalPort;
                } else {
                    unset($_SERVER['SERVER_PORT']);
                }
                throw new \RuntimeException("Provider {$providerId} not found");
            }

            // Debug SAML response
            $this->logInfo('Processing SAML response. POST data keys: {keys}', ['keys' => array_keys($_POST)]);
            $this->logInfo('SAML Response length: {length}', ['length' => strlen($_POST['SAMLResponse'] ?? '')]);

            // Process the SAML response
            $auth->processResponse();

            $errors = $auth->getErrors();
            if (!empty($errors)) {
                $errorMsg = implode(', ', $errors);
                $this->logError('SAML assertion processing errors: {errors}', ['errors' => $errorMsg]);
                $this->logError('Last error reason: {reason}', ['reason' => $auth->getLastErrorReason()]);

                // Get more detailed error information
                $lastErrorException = $auth->getLastErrorException();
                if ($lastErrorException) {
                    $this->logError('SAML error exception: {exception}', ['exception' => $lastErrorException->getMessage()]);
                }

                $sessionEntity = new SessionEntity(_('Authentication failed: ') . $errorMsg, 'danger');
                $this->authenticationService->auth($sessionEntity);
                return;
            }

            if (!$auth->isAuthenticated()) {
                $this->logWarning('SAML authentication failed - not authenticated');
                $sessionEntity = new SessionEntity(_('Authentication failed: Invalid SAML response'), 'danger');
                $this->authenticationService->auth($sessionEntity);
                return;
            }

            // Get user information from SAML attributes
            $userInfo = $this->getUserInfoFromAssertion($auth, $providerId);

            // Log user info details
            $this->logInfo('SAML User Info received: {userinfo}', [
                'userinfo' => [
                    'username' => $userInfo->getUsername(),
                    'email' => $userInfo->getEmail(),
                    'display_name' => $userInfo->getDisplayName(),
                    'name_id' => $userInfo->getNameId(),
                    'groups' => $userInfo->getGroups(),
                    'provider' => $userInfo->getProviderId(),
                    'is_valid' => $userInfo->isValid()
                ]
            ]);

            // Provision or update user (reuse OIDC provisioning service)
            $userId = $this->userProvisioningService->provisionUser($userInfo, $providerId);

            if ($userId) {
                $this->logInfo('Successfully authenticated SAML user: {username}', ['username' => $userInfo->getUsername()]);

                // Get the actual database username
                $databaseUsername = $this->userProvisioningService->getDatabaseUsername($userId);
                if (!$databaseUsername) {
                    $this->logError('Could not get database username for user ID: {userId}', ['userId' => $userId]);
                    $databaseUsername = $userInfo->getUsername();
                }

                $this->logInfo('Using database username for session: {username}', ['username' => $databaseUsername]);

                // Set userlogin for MFA verification page
                $this->setSessionValue('userlogin', $databaseUsername);

                // Ensure a CSRF token exists for subsequent requests
                $this->csrfTokenService->ensureTokenExists();
                $this->logInfo('CSRF token ensured for SAML session.');

                // Check if MFA is globally enabled
                $mfaGloballyEnabled = $this->configManager->get('security', 'mfa.enabled', false);

                // Check if MFA is enabled for this user
                $mfaRequired = $mfaGloballyEnabled && $this->mfaService->isMfaEnabled($userId);

                if ($mfaRequired) {
                    $this->logInfo('MFA is required for SAML user {username}', ['username' => $databaseUsername]);

                    // Store user details temporarily for MFA verification - DO NOT set userid yet!
                    // This prevents API requests from bypassing MFA by checking isAuthenticated()
                    $this->setSessionValue('pending_userid', $userId);
                    $this->setSessionValue('pending_name', $userInfo->getDisplayName());
                    $this->setSessionValue('pending_email', $userInfo->getEmail());
                    $this->setSessionValue('pending_auth_used', UserProvisioningService::AUTH_METHOD_SAML);

                    // Store SAML-specific data as pending
                    $this->setSessionValue('pending_saml_provider', $providerId);
                    $this->setSessionValue('pending_saml_name_id', $userInfo->getNameId());
                    $this->setSessionValue('pending_saml_session_index', $userInfo->getSessionIndex());

                    // Use our centralized MFA session manager to set MFA required
                    MfaSessionManager::setMfaRequired($userId);

                    // Redirect to MFA verification
                    $baseUrlPrefix = $this->configManager->get('interface', 'base_url_prefix', '');
                    $redirectUrl = $baseUrlPrefix . '/mfa/verify';
                    header("Location: $redirectUrl", true, 302);
                    exit;
                } else {
                    // No MFA required, proceed with full authentication
                    // NOW it's safe to set userid since MFA is not required
                    $this->setSessionValue('userid', $userId);
                    $this->setSessionValue('name', $userInfo->getDisplayName());
                    $this->setSessionValue('userfullname', $userInfo->getDisplayName());
                    $this->setSessionValue('email', $userInfo->getEmail());
                    $this->setSessionValue('useremail', $userInfo->getEmail());
                    $this->setSessionValue('auth_used', UserProvisioningService::AUTH_METHOD_SAML);
                    $this->setSessionValue('auth_method_used', UserProvisioningService::AUTH_METHOD_SAML);
                    $this->setSessionValue('authenticated', true);
                    $this->setSessionValue('mfa_required', false);

                    // Set SAML-specific session variables for logout detection
                    $this->setSessionValue('saml_authenticated', true);
                    $this->setSessionValue('saml_provider', $providerId);
                    $this->setSessionValue('saml_name_id', $userInfo->getNameId());
                    $this->setSessionValue('saml_session_index', $userInfo->getSessionIndex());

                    $this->authenticationService->redirectToIndex();
                }
            } else {
                $this->logWarning('Failed to provision SAML user: {username}', ['username' => $userInfo->getUsername()]);
                $sessionEntity = new SessionEntity(_('Authentication failed: Unable to create or update user account'), 'danger');
                $this->authenticationService->auth($sessionEntity);

                // Clean up session data on provisioning failure
                $this->unsetSessionValue('saml_provider');
            }

            // Restore original $_SERVER values after successful processing
            if (isset($originalHttps)) {
                if ($originalHttps !== null) {
                    $_SERVER['HTTPS'] = $originalHttps;
                } else {
                    unset($_SERVER['HTTPS']);
                }
            }
            if (isset($originalPort)) {
                if ($originalPort !== null) {
                    $_SERVER['SERVER_PORT'] = $originalPort;
                } else {
                    unset($_SERVER['SERVER_PORT']);
                }
            }
        } catch (\Exception $e) {
            // Restore original $_SERVER values before handling error
            if (isset($originalHttps)) {
                if ($originalHttps !== null) {
                    $_SERVER['HTTPS'] = $originalHttps;
                } else {
                    unset($_SERVER['HTTPS']);
                }
            }
            if (isset($originalPort)) {
                if ($originalPort !== null) {
                    $_SERVER['SERVER_PORT'] = $originalPort;
                } else {
                    unset($_SERVER['SERVER_PORT']);
                }
            }

            $this->logError('SAML authentication error: {error}', ['error' => $e->getMessage()]);
            $sessionEntity = new SessionEntity(_('Authentication failed: ') . $e->getMessage(), 'danger');
            $this->authenticationService->auth($sessionEntity);

            // Clean up session data on exception
            $this->unsetSessionValue('saml_provider');
        }
    }

    public function initiateSingleLogout(string $providerId): ?string
    {
        try {
            $this->logInfo('Initiating SAML single logout for provider: {provider}', ['provider' => $providerId]);

            $auth = $this->createAuth($providerId);
            if (!$auth) {
                $this->logError('Failed to create SAML auth for logout: {provider}', ['provider' => $providerId]);
                return null;
            }

            $nameId = $this->getSessionValue('saml_name_id');
            $sessionIndex = $this->getSessionValue('saml_session_index');

            if ($nameId) {
                return $auth->logout(null, [], $nameId, $sessionIndex, true);
            }

            return null;
        } catch (\Exception $e) {
            $this->logError('Error initiating SAML logout for {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function handleSingleLogout(): void
    {
        $this->logInfo('Handling SAML single logout');

        $providerId = $this->getSessionValue('saml_provider', '');
        if (empty($providerId)) {
            $this->logWarning('No provider ID in session during SAML logout');
            return;
        }

        try {
            $auth = $this->createAuth($providerId);
            if ($auth) {
                $auth->processSLO();

                $errors = $auth->getErrors();
                if (!empty($errors)) {
                    $this->logError('SAML logout errors: {errors}', ['errors' => implode(', ', $errors)]);
                }
            }
        } catch (\Exception $e) {
            $this->logError('SAML logout error: {error}', ['error' => $e->getMessage()]);
        }

        // Clear SAML session data
        $this->unsetSessionValue('saml_authenticated');
        $this->unsetSessionValue('saml_provider');
        $this->unsetSessionValue('saml_name_id');
        $this->unsetSessionValue('saml_session_index');

        // If SLO was pending, destroy the entire session now
        if ($this->getSessionValue('saml_slo_pending', false)) {
            $this->unsetSessionValue('saml_slo_pending');
            session_destroy();
        }
    }

    public function generateMetadata(string $providerId): string
    {
        try {
            $settings = new Settings($this->samlConfigurationService->generateOneLoginSettings($providerId));
            $metadata = $settings->getSPMetadata();

            $errors = $settings->validateMetadata($metadata);
            if (!empty($errors)) {
                $this->logError('SAML metadata validation errors: {errors}', ['errors' => implode(', ', $errors)]);
                throw new \RuntimeException('Invalid metadata generated: ' . implode(', ', $errors));
            }

            return $metadata;
        } catch (\Exception $e) {
            $this->logError('Error generating SAML metadata for {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function createAuth(string $providerId): ?Auth
    {
        try {
            $settings = $this->samlConfigurationService->generateOneLoginSettings($providerId);
            return new Auth($settings);
        } catch (\Exception $e) {
            $this->logError('Failed to create SAML Auth: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getUserInfoFromAssertion(Auth $auth, string $providerId): SamlUserInfo
    {
        $attributes = $auth->getAttributes();
        $nameId = $auth->getNameId();
        $sessionIndex = $auth->getSessionIndex();

        $config = $this->samlConfigurationService->getProviderConfig($providerId);
        $mapping = $config['user_mapping'] ?? [];

        // Extract user information from SAML attributes using configured mapping
        $username = $this->getAttributeValue($attributes, $mapping['username'] ?? 'username') ?: $nameId;
        $email = $this->getAttributeValue($attributes, $mapping['email'] ?? 'email');
        $firstName = $this->getAttributeValue($attributes, $mapping['first_name'] ?? 'firstName');
        $lastName = $this->getAttributeValue($attributes, $mapping['last_name'] ?? 'lastName');
        $displayName = $this->getAttributeValue($attributes, $mapping['display_name'] ?? 'displayName');
        $groups = $this->getAttributeValue($attributes, $mapping['groups'] ?? 'groups', [], true);

        return new SamlUserInfo(
            username: $username,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            displayName: $displayName,
            groups: $groups,
            providerId: $providerId,
            nameId: $nameId,
            sessionIndex: $sessionIndex,
            rawAttributes: $attributes
        );
    }

    private function getAttributeValue(array $attributes, string $attributeName, $default = '', bool $multiValue = false)
    {
        if (!isset($attributes[$attributeName])) {
            return $default;
        }

        $value = $attributes[$attributeName];

        // For multi-valued attributes (like groups), return the full array
        if ($multiValue) {
            if (is_array($value)) {
                return $value;
            }
            // Single value should still be returned as array for consistency
            return $value ? [$value] : $default;
        }

        // For single-valued attributes, get the first value
        if (is_array($value)) {
            return $value[0] ?? $default;
        }

        return $value;
    }

    private function isProviderEnabled(string $providerId, array $config): bool
    {
        // Respect the enabled flag - if explicitly set to false, provider is disabled
        if (isset($config['enabled']) && !$config['enabled']) {
            return false;
        }

        // For display purposes, only require basic URL configuration
        // Certificate validation will happen during actual authentication
        return !empty($config['entity_id']) && !empty($config['sso_url']);
    }

    /**
     * Session management methods following existing patterns
     */
    private function setSessionValue(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    private function getSessionValue(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    private function unsetSessionValue(string $key): void
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Detect if we're running behind a reverse proxy that terminates SSL
     * Uses standard proxy detection methods without hardcoded service names
     */
    private function isReverseProxyEnvironment(): bool
    {
        // Check for standard reverse proxy headers
        $proxyHeaders = [
            'HTTP_X_FORWARDED_PROTO',
            'HTTP_X_FORWARDED_SSL',
            'HTTP_X_FORWARDED_HOST',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CF_VISITOR',
            'HTTP_X_FORWARDED_SCHEME',
            'HTTP_FORWARDED'
        ];

        foreach ($proxyHeaders as $header) {
            if (isset($_SERVER[$header])) {
                return true;
            }
        }

        // Check if HTTPS is expected in configuration but not detected in environment
        $spConfig = $this->configManager->get('saml', 'sp', []);
        $configuredAcsUrl = $spConfig['assertion_consumer_service_url'] ?? '';
        if (str_starts_with($configuredAcsUrl, 'https://') && empty($_SERVER['HTTPS'])) {
            return true;
        }

        // Check for protocol mismatch in X-Forwarded-Proto
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwardedProto === 'https' && empty($_SERVER['HTTPS'])) {
            return true;
        }

        return false;
    }
}
