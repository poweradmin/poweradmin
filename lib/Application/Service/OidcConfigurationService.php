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

            // Process URL templates before validation
            $config = $this->processUrlTemplates($config);

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

            $metadata = @file_get_contents($metadataUrl, false, $context);

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

    private function processUrlTemplates(array $config): array
    {
        // Define which configuration keys should have URL template processing
        $urlFields = [
            'metadata_url',
            'authorize_url',
            'token_url',
            'userinfo_url',
            'logout_url'
        ];

        foreach ($urlFields as $field) {
            if (isset($config[$field]) && is_string($config[$field])) {
                $config[$field] = $this->replaceUrlPlaceholders($config[$field], $config);
            }
        }

        return $config;
    }

    private function replaceUrlPlaceholders(string $url, array $config): string
    {
        // Define mappings for common OIDC provider placeholders
        $placeholders = [
            '{tenant}' => $config['tenant'] ?? '',
            '{base_url}' => $config['base_url'] ?? '',
            '{realm}' => $config['realm'] ?? '',
            '{domain}' => $config['domain'] ?? '',
            '{application_slug}' => $config['application_slug'] ?? '',
        ];

        // Replace placeholders with actual values
        foreach ($placeholders as $placeholder => $value) {
            if (!empty($value)) {
                $url = str_replace($placeholder, $value, $url);
            }
        }

        return $url;
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

    public function validatePermissionTemplateMapping(): array
    {
        $errors = [];
        $mapping = $this->configManager->get('oidc', 'permission_template_mapping', []);

        // Empty mapping is allowed - will use default_permission_template fallback
        if (empty($mapping)) {
            // Check if default template exists before claiming it will be used
            $defaultTemplate = $this->configManager->get('oidc', 'default_permission_template', '');
            if (empty($defaultTemplate)) {
                $this->logWarning('No permission template mapping configured and no default_permission_template defined');
            } else {
                $this->logWarning('No permission template mapping configured, will use default_permission_template for all users');
            }
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

        return $errors;
    }

    public function isEnabled(): bool
    {
        return (bool)$this->configManager->get('oidc', 'enabled', false);
    }
}
