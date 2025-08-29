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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

class OidcConfigurationService extends LoggingService
{
    private ConfigurationManager $configManager;
    private array $discoveredConfigs = [];

    public function __construct(ConfigurationManager $configManager, Logger $logger)
    {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->configManager = $configManager;
    }

    public function getProviderConfig(string $providerId): ?array
    {
        try {
            $providers = $this->configManager->get('oidc', 'providers', []);

            if (!isset($providers[$providerId])) {
                $this->logWarning('OIDC provider not found: {provider}', ['provider' => $providerId]);
                return null;
            }

            $config = $providers[$providerId];

            // Validate required fields before processing
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                $this->logError('Missing required OIDC configuration for provider: {provider}', ['provider' => $providerId]);
                return null;
            }

            // If auto-discovery is enabled, attempt to discover endpoints
            if ($config['auto_discovery'] ?? false) {
                $config = $this->discoverProviderEndpoints($providerId, $config);
                if (!$config) {
                    $this->logError('Failed to discover OIDC endpoints for provider: {provider}', ['provider' => $providerId]);
                    return null;
                }
            }

            return $this->validateProviderConfig($config) ? $config : null;
        } catch (\Exception $e) {
            $this->logError('Error getting OIDC provider config for {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getAllProviderConfigs(): array
    {
        $providers = $this->configManager->get('oidc', 'providers', []);
        $validConfigs = [];

        foreach ($providers as $providerId => $config) {
            $validConfig = $this->getProviderConfig($providerId);
            if ($validConfig) {
                $validConfigs[$providerId] = $validConfig;
            }
        }

        return $validConfigs;
    }

    private function discoverProviderEndpoints(string $providerId, array $config): array
    {
        $metadataUrl = $config['metadata_url'] ?? '';

        if (empty($metadataUrl)) {
            $this->logWarning(
                'Auto-discovery enabled but no metadata URL provided for provider: {provider}',
                ['provider' => $providerId]
            );
            return $config;
        }

        // Check cache first
        if (isset($this->discoveredConfigs[$providerId])) {
            return array_merge($config, $this->discoveredConfigs[$providerId]);
        }

        try {
            $this->logInfo('Discovering OIDC endpoints for provider: {provider}', ['provider' => $providerId]);

            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Poweradmin OIDC Client'
                ]
            ]);

            $metadata = file_get_contents($metadataUrl, false, $context);

            if ($metadata === false) {
                throw new \RuntimeException("Failed to fetch metadata from: {$metadataUrl}");
            }

            $discoveredData = json_decode($metadata, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON in metadata response");
            }

            $discoveredConfig = [
                'authorize_url' => $discoveredData['authorization_endpoint'] ?? '',
                'token_url' => $discoveredData['token_endpoint'] ?? '',
                'userinfo_url' => $discoveredData['userinfo_endpoint'] ?? '',
                'logout_url' => $discoveredData['end_session_endpoint'] ?? '',
                'scopes_supported' => $discoveredData['scopes_supported'] ?? [],
                'response_types_supported' => $discoveredData['response_types_supported'] ?? [],
            ];

            // Cache the discovered configuration
            $this->discoveredConfigs[$providerId] = $discoveredConfig;

            $this->logInfo('Successfully discovered OIDC endpoints for provider: {provider}', ['provider' => $providerId]);

            return array_merge($config, $discoveredConfig);
        } catch (\Exception $e) {
            $this->logError('Failed to discover OIDC endpoints for provider {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            return $config;
        }
    }

    private function validateProviderConfig(array $config): bool
    {
        $required = ['client_id', 'client_secret', 'authorize_url', 'token_url', 'userinfo_url'];

        foreach ($required as $field) {
            if (empty($config[$field])) {
                return false;
            }
        }

        return true;
    }

    public function getProviderDisplayName(string $providerId): string
    {
        $config = $this->getProviderConfig($providerId);
        return $config['display_name'] ?? $config['name'] ?? ucfirst($providerId);
    }

    public function isProviderEnabled(string $providerId): bool
    {
        $config = $this->getProviderConfig($providerId);
        return $config !== null;
    }

    /**
     * Get predefined provider configurations (Azure AD, Keycloak, Okta, Ping, Authentik, etc.)
     */
    public function getProviderTemplate(string $providerType): array
    {
        $templates = [
            'azure' => [
                'name' => 'Microsoft Azure AD',
                'display_name' => 'Sign in with Microsoft',
                'auto_discovery' => true,
                'metadata_url' => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid_configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ]
            ],
            'keycloak' => [
                'name' => 'Keycloak',
                'display_name' => 'Sign in with Keycloak',
                'auto_discovery' => true,
                'metadata_url' => '{base_url}/auth/realms/{realm}/.well-known/openid_configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ]
            ],
            'okta' => [
                'name' => 'Okta',
                'display_name' => 'Sign in with Okta',
                'auto_discovery' => true,
                'metadata_url' => 'https://{domain}/.well-known/openid_configuration',
                'scopes' => 'openid profile email groups',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ],
                'additional_params' => [
                    'prompt' => 'select_account'
                ]
            ],
            'ping' => [
                'name' => 'Ping Identity',
                'display_name' => 'Sign in with PingIdentity',
                'auto_discovery' => true,
                'metadata_url' => 'https://{domain}/.well-known/openid_configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'sub',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'memberOf',
                ]
            ],
            'authentik' => [
                'name' => 'Authentik (goauthentik)',
                'display_name' => 'Sign in with Authentik',
                'auto_discovery' => true,
                'metadata_url' => '{base_url}/application/o/{application_slug}/.well-known/openid_configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ]
            ],
            'google' => [
                'name' => 'Google',
                'display_name' => 'Sign in with Google',
                'auto_discovery' => true,
                'metadata_url' => 'https://accounts.google.com/.well-known/openid_configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'email',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ]
            ],
            'auth0' => [
                'name' => 'Auth0',
                'display_name' => 'Sign in with Auth0',
                'auto_discovery' => true,
                'metadata_url' => 'https://{domain}/.well-known/openid_configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'nickname',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'https://schemas.auth0.com/groups',
                ]
            ],
            'generic' => [
                'name' => 'Generic OIDC',
                'display_name' => 'Sign in with OIDC',
                'auto_discovery' => false,
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ]
            ]
        ];

        return $templates[$providerType] ?? $templates['generic'];
    }

    /**
     * Get available provider types with descriptions
     */
    public function getAvailableProviderTypes(): array
    {
        return [
            'azure' => [
                'name' => 'Microsoft Azure AD',
                'description' => 'Microsoft Azure Active Directory / Entra ID',
                'requires' => ['tenant']
            ],
            'keycloak' => [
                'name' => 'Keycloak',
                'description' => 'Red Hat Keycloak identity and access management',
                'requires' => ['base_url', 'realm']
            ],
            'okta' => [
                'name' => 'Okta',
                'description' => 'Okta identity management platform',
                'requires' => ['domain']
            ],
            'ping' => [
                'name' => 'Ping Identity',
                'description' => 'Ping Identity access management',
                'requires' => ['domain']
            ],
            'authentik' => [
                'name' => 'Authentik',
                'description' => 'goauthentik.io - Open-source identity provider',
                'requires' => ['base_url', 'application_slug']
            ],
            'google' => [
                'name' => 'Google',
                'description' => 'Google identity platform',
                'requires' => []
            ],
            'auth0' => [
                'name' => 'Auth0',
                'description' => 'Auth0 identity platform',
                'requires' => ['domain']
            ],
            'generic' => [
                'name' => 'Generic OIDC',
                'description' => 'Generic OpenID Connect provider',
                'requires' => ['authorize_url', 'token_url', 'userinfo_url']
            ]
        ];
    }

    public function validatePermissionTemplateMapping(): array
    {
        $errors = [];
        $mapping = $this->configManager->get('oidc', 'permission_template_mapping', []);

        if (empty($mapping)) {
            $errors[] = 'No OIDC permission template mapping configured';
            return $errors;
        }

        foreach ($mapping as $group => $template) {
            if (empty($group) || empty($template)) {
                $errors[] = "Invalid mapping: empty group or template name";
            }

            if (!is_string($group) || !is_string($template)) {
                $errors[] = "Invalid mapping: group and template names must be strings";
            }
        }

        // Validate default permission template
        $defaultTemplate = $this->configManager->get('oidc', 'default_permission_template', '');
        if (empty($defaultTemplate)) {
            $errors[] = 'Default permission template not configured';
        }

        return $errors;
    }

    public function isEnabled(): bool
    {
        return (bool)$this->configManager->get('oidc', 'enabled', false);
    }
}
