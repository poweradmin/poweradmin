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

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Service\RedirectService;
use ReflectionClass;

class OidcService extends LoggingService
{
    private ConfigurationManager $configManager;
    private AuthenticationService $authenticationService;
    private SessionService $sessionService;
    private OidcConfigurationService $oidcConfigurationService;
    private UserProvisioningService $userProvisioningService;
    private Request $request;
    private CsrfTokenService $csrfTokenService;
    private PDOCommon $db;
    private ?MfaService $mfaService = null;

    public function __construct(
        ConfigurationManager $configManager,
        OidcConfigurationService $oidcConfigurationService,
        UserProvisioningService $userProvisioningService,
        Logger $logger,
        PDOCommon $db,
        ?Request $request = null
    ) {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->configManager = $configManager;
        $this->oidcConfigurationService = $oidcConfigurationService;
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
        return $this->configManager->get('oidc', 'enabled', false);
    }

    public function getAvailableProviders(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        // Validate OIDC configuration first
        $configErrors = $this->oidcConfigurationService->validatePermissionTemplateMapping();
        if (!empty($configErrors)) {
            $this->logError('Configuration validation failed: {errors}', ['errors' => implode(', ', $configErrors)]);
            return [];
        }

        $providers = $this->configManager->get('oidc', 'providers', []);
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
            $this->logInfo('Initiating OIDC auth flow for provider: {provider}', ['provider' => $providerId]);

            // Validate configuration before starting flow
            $configErrors = $this->oidcConfigurationService->validatePermissionTemplateMapping();
            if (!empty($configErrors)) {
                $this->logError('Cannot initiate OIDC flow - configuration errors: {errors}', [
                    'errors' => implode(', ', $configErrors)
                ]);
                throw new \RuntimeException('Configuration validation failed: ' . implode(', ', $configErrors));
            }

            $provider = $this->createProvider($providerId);
            if (!$provider) {
                $this->logError('Failed to create OIDC provider: {provider}', ['provider' => $providerId]);
                throw new \RuntimeException("Provider {$providerId} not found or not configured");
            }

            // Generate state parameter for security
            $state = bin2hex(random_bytes(16));
            $this->setSessionValue('oidc_state', $state);
            $this->setSessionValue('oidc_provider', $providerId);

            // Generate PKCE parameters for enhanced security
            $codeVerifier = $this->generateCodeVerifier();
            $codeChallenge = $this->generateCodeChallenge($codeVerifier);
            $this->setSessionValue('oidc_code_verifier', $codeVerifier);

            // Get provider configuration for scopes
            $config = $this->oidcConfigurationService->getProviderConfig($providerId);
            $scopes = $config['scopes'] ?? 'openid profile email';

            $authUrl = $provider->getAuthorizationUrl([
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'scope' => $scopes,
            ]);

            $this->logInfo('Generated OIDC authorization URL: {url}', ['url' => $authUrl]);

            return $authUrl;
        } catch (\Exception $e) {
            $this->logError('Error initiating OIDC auth flow for {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function handleCallback(): void
    {
        $this->logInfo('Handling OIDC callback');

        // Validate state parameter
        $receivedState = $this->request->getQueryParam('state');
        $sessionState = $this->getSessionValue('oidc_state');

        if (empty($receivedState) || $receivedState !== $sessionState) {
            $this->logWarning('Invalid state parameter in OIDC callback');
            $sessionEntity = new SessionEntity(_('Authentication failed: Invalid state parameter'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        // Check for error parameter from OIDC provider
        $error = $this->request->getQueryParam('error');
        if (!empty($error)) {
            $errorDescription = $this->request->getQueryParam('error_description', 'Unknown error');
            $this->logError('OIDC provider returned error: {error} - {description}', [
                'error' => $error,
                'description' => $errorDescription
            ]);
            $sessionEntity = new SessionEntity(_('Authentication failed: ') . $errorDescription, 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        $code = $this->request->getQueryParam('code');
        if (empty($code)) {
            $this->logWarning('No authorization code in OIDC callback');
            $sessionEntity = new SessionEntity(_('Authentication failed: No authorization code'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        $providerId = $this->getSessionValue('oidc_provider', '');
        if (empty($providerId)) {
            $this->logWarning('No provider ID in session during OIDC callback');
            $sessionEntity = new SessionEntity(_('Authentication failed: Invalid session'), 'danger');
            $this->authenticationService->auth($sessionEntity);
            return;
        }

        try {
            $provider = $this->createProvider($providerId);
            if (!$provider) {
                throw new \RuntimeException("Provider {$providerId} not found");
            }

            // Get PKCE code verifier from session
            $codeVerifier = $this->getSessionValue('oidc_code_verifier');

            // Exchange authorization code for access token with PKCE
            $tokenParams = ['code' => $code];
            if ($codeVerifier) {
                $tokenParams['code_verifier'] = $codeVerifier;
            }

            try {
                $token = $provider->getAccessToken('authorization_code', $tokenParams);
            } catch (\Exception $e) {
                $this->logError('OIDC authentication error: {error}', ['error' => $e->getMessage()]);
                throw $e;
            }

            // Get user information
            $userInfo = $this->getUserInfo($provider, $token, $providerId);

            // Log raw data to see all available fields
            $this->logInfo('OIDC Raw User Data: {rawdata}', [
                'rawdata' => $userInfo->getRawData()
            ]);

            // Log user info details
            $this->logInfo('OIDC User Info received: {userinfo}', [
                'userinfo' => [
                    'username' => $userInfo->getUsername(),
                    'email' => $userInfo->getEmail(),
                    'display_name' => $userInfo->getDisplayName(),
                    'subject' => $userInfo->getSubject(),
                    'groups' => $userInfo->getGroups(),
                    'provider' => $userInfo->getProviderId(),
                    'is_valid' => $userInfo->isValid()
                ]
            ]);

            // Provision or update user
            $userId = $this->userProvisioningService->provisionUser($userInfo, $providerId);

            // Log provisioning result
            if ($userId) {
                $this->logInfo('User provisioning successful, user ID: {userId}', ['userId' => $userId]);
            } else {
                $this->logError('User provisioning failed - returned null');
            }

            if ($userId) {
                $this->logInfo('Successfully authenticated OIDC user: {username}', ['username' => $userInfo->getUsername()]);

                // Get the actual database username (important for existing users linked by email)
                $databaseUsername = $this->userProvisioningService->getDatabaseUsername($userId);
                if (!$databaseUsername) {
                    $this->logError('Could not get database username for user ID: {userId}', ['userId' => $userId]);
                    $databaseUsername = $userInfo->getUsername(); // Fallback to OIDC username
                }

                $this->logInfo('Using database username for session: {username}', ['username' => $databaseUsername]);

                // Set userlogin for MFA verification page
                $this->setSessionValue('userlogin', $databaseUsername);

                // Ensure a CSRF token exists for subsequent requests
                $this->csrfTokenService->ensureTokenExists();
                $this->logInfo('CSRF token ensured for OIDC session.');

                // Check if MFA is globally enabled
                $mfaGloballyEnabled = $this->configManager->get('security', 'mfa.enabled', false);

                // Check if MFA is enabled for this user
                $mfaRequired = $mfaGloballyEnabled && $this->mfaService->isMfaEnabled($userId);

                if ($mfaRequired) {
                    $this->logInfo('MFA is required for OIDC user {username}', ['username' => $databaseUsername]);

                    // Store user details temporarily for MFA verification - DO NOT set userid yet!
                    // This prevents API requests from bypassing MFA by checking isAuthenticated()
                    $this->setSessionValue('pending_userid', $userId);
                    $this->setSessionValue('pending_name', $userInfo->getDisplayName());
                    $this->setSessionValue('pending_email', $userInfo->getEmail());
                    $this->setSessionValue('pending_auth_used', 'oidc');

                    // Store OIDC-specific data as pending
                    $this->setSessionValue('pending_oidc_provider', $providerId);
                    if ($userInfo->getAvatarUrl()) {
                        $this->setSessionValue('pending_oauth_avatar_url', $userInfo->getAvatarUrl());
                    }

                    // Use our centralized MFA session manager to set MFA required
                    MfaSessionManager::setMfaRequired($userId);

                    // Clean up temporary session data
                    $this->unsetSessionValue('oidc_state');
                    $this->unsetSessionValue('oidc_code_verifier');

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
                    $this->setSessionValue('auth_used', 'oidc');
                    $this->setSessionValue('auth_method_used', 'oidc');
                    $this->setSessionValue('authenticated', true);
                    $this->setSessionValue('mfa_required', false);

                    // Set OIDC-specific session variables for logout detection
                    $this->setSessionValue('oidc_authenticated', true);
                    $this->setSessionValue('oidc_provider', $providerId);

                    // Store OAuth avatar URL if available
                    if ($userInfo->getAvatarUrl()) {
                        $this->setSessionValue('oauth_avatar_url', $userInfo->getAvatarUrl());
                    }

                    // Clean up temporary session data
                    $this->unsetSessionValue('oidc_state');
                    $this->unsetSessionValue('oidc_code_verifier');

                    $this->authenticationService->redirectToIndex();
                }
            } else {
                $this->logWarning('Failed to provision OIDC user: {username}', ['username' => $userInfo->getUsername()]);
                $sessionEntity = new SessionEntity(_('Authentication failed: Unable to create or update user account'), 'danger');
                $this->authenticationService->auth($sessionEntity);
            }
        } catch (\Exception $e) {
            $this->logError('OIDC authentication error: {error}', ['error' => $e->getMessage()]);
            $sessionEntity = new SessionEntity(_('Authentication failed: ') . $e->getMessage(), 'danger');
            $this->authenticationService->auth($sessionEntity);
        }

        // Clean up session data (in error cases only)
        $this->unsetSessionValue('oidc_state');
        $this->unsetSessionValue('oidc_code_verifier');
    }

    private function createProvider(string $providerId): ?GenericProvider
    {
        $config = $this->oidcConfigurationService->getProviderConfig($providerId);
        if (!$config) {
            return null;
        }

        return new GenericProvider([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $this->getCallbackUrl(),
            'urlAuthorize' => $config['authorize_url'],
            'urlAccessToken' => $config['token_url'],
            'urlResourceOwnerDetails' => $config['userinfo_url'],
        ]);
    }

    private function getUserInfo(GenericProvider $provider, AccessTokenInterface $token, string $providerId): OidcUserInfo
    {
        // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType - getAccessToken returns interface but getResourceOwner expects concrete class
        $resourceOwner = $provider->getResourceOwner($token);
        $userData = $resourceOwner->toArray();

        $config = $this->oidcConfigurationService->getProviderConfig($providerId);
        $mapping = $config['user_mapping'] ?? [];

        return new OidcUserInfo(
            username: $userData[$mapping['username'] ?? 'preferred_username'] ?? $userData['sub'] ?? '',
            email: $userData[$mapping['email'] ?? 'email'] ?? '',
            firstName: $userData[$mapping['first_name'] ?? 'given_name'] ?? '',
            lastName: $userData[$mapping['last_name'] ?? 'family_name'] ?? '',
            displayName: $userData[$mapping['display_name'] ?? 'name'] ?? '',
            groups: $userData[$mapping['groups'] ?? 'groups'] ?? [],
            providerId: $providerId,
            subject: $userData[$mapping['subject'] ?? 'sub'] ?? '',
            rawData: $userData,
            avatarUrl: $userData[$mapping['avatar'] ?? 'picture'] ?? null
        );
    }

    private function getCallbackUrl(): string
    {
        $scheme = $this->detectScheme();
        $host = $this->request->getServerParam('HTTP_HOST', 'localhost');
        $basePrefix = $this->configManager->get('interface', 'base_url_prefix', '');

        return $scheme . '://' . $host . $basePrefix . '/oidc/callback';
    }

    private function detectScheme(): string
    {
        // Check for reverse proxy headers first (common in Docker/Kubernetes environments)
        $forwardedProto = $this->request->getServerParam('HTTP_X_FORWARDED_PROTO');
        if ($forwardedProto) {
            return strtolower($forwardedProto) === 'https' ? 'https' : 'http';
        }

        // Alternative header format
        $forwardedSsl = $this->request->getServerParam('HTTP_X_FORWARDED_SSL');
        if ($forwardedSsl && strtolower($forwardedSsl) === 'on') {
            return 'https';
        }

        // Check standard HTTPS indicator
        $https = $this->request->getServerParam('HTTPS');
        if ($https && strtolower($https) !== 'off') {
            return 'https';
        }

        // Check for secure port
        $port = $this->request->getServerParam('SERVER_PORT');
        if ($port && (int)$port === 443) {
            return 'https';
        }

        // Fall back to REQUEST_SCHEME or default to https for security
        return $this->request->getServerParam('REQUEST_SCHEME', 'https');
    }

    private function isProviderEnabled(string $providerId, array $config): bool
    {
        return !empty($config['client_id']) && !empty($config['client_secret']);
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
     * PKCE (Proof Key for Code Exchange) helper methods for enhanced security
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateCodeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
