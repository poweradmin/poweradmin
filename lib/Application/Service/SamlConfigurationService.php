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

class SamlConfigurationService extends LoggingService
{
    private ConfigurationManager $configManager;

    public function __construct(ConfigurationManager $configManager, Logger $logger)
    {
        $shortClassName = (new ReflectionClass(self::class))->getShortName();
        parent::__construct($logger, $shortClassName);

        $this->configManager = $configManager;
    }

    public function getProviderConfig(string $providerId): ?array
    {
        try {
            $providers = $this->configManager->get('saml', 'providers', []);

            if (!isset($providers[$providerId])) {
                $this->logWarning('SAML provider not found: {provider}', ['provider' => $providerId]);
                return null;
            }

            $config = $providers[$providerId];

            // Process URL templates before validation
            $config = $this->processUrlTemplates($config);

            // Validate required fields
            if (empty($config['entity_id']) || empty($config['sso_url'])) {
                $this->logError('Missing required SAML configuration for provider: {provider}', ['provider' => $providerId]);
                return null;
            }

            return $this->validateProviderConfig($config) ? $config : null;
        } catch (\Exception $e) {
            $this->logError('Error getting SAML provider config for {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getAllProviderConfigs(): array
    {
        $providers = $this->configManager->get('saml', 'providers', []);
        $validConfigs = [];

        foreach ($providers as $providerId => $config) {
            $validConfig = $this->getProviderConfig($providerId);
            if ($validConfig) {
                $validConfigs[$providerId] = $validConfig;
            }
        }

        return $validConfigs;
    }

    public function getServiceProviderConfig(): array
    {
        $spConfig = $this->configManager->get('saml', 'sp', []);

        // Provide defaults for required fields
        $defaults = [
            'entity_id' => $this->generateDefaultEntityId(),
            'assertion_consumer_service_url' => $this->generateDefaultAcsUrl(),
            'single_logout_service_url' => $this->generateDefaultSloUrl(),
            'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'x509cert' => '',
            'private_key' => '',
        ];

        return array_merge($defaults, $spConfig);
    }

    private function generateDefaultEntityId(): string
    {
        $baseUrl = $this->getBaseUrl();
        return $baseUrl . '/saml/metadata';
    }

    private function generateDefaultAcsUrl(): string
    {
        $baseUrl = $this->getBaseUrl();
        return $baseUrl . '/saml/acs';
    }

    private function generateDefaultSloUrl(): string
    {
        $baseUrl = $this->getBaseUrl();
        return $baseUrl . '/saml/sls';
    }

    private function getBaseUrl(): string
    {
        // Try to get from configuration first
        $configuredBaseUrl = $this->configManager->get('interface', 'base_url', '');
        if (!empty($configuredBaseUrl)) {
            return rtrim($configuredBaseUrl, '/');
        }

        // Fallback to detecting from server environment
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $prefix = $this->configManager->get('interface', 'base_url_prefix', '');

        return $scheme . '://' . $host . $prefix;
    }

    private function processUrlTemplates(array $config): array
    {
        $urlFields = [
            'entity_id',
            'sso_url',
            'slo_url'
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
        $placeholders = [
            '{tenant-id}' => $config['tenant_id'] ?? '',
            '{tenant}' => $config['tenant'] ?? '',
            '{domain}' => $config['domain'] ?? '',
            '{realm}' => $config['realm'] ?? '',
            '{app-id}' => $config['app_id'] ?? '',
            '{app-name}' => $config['app_name'] ?? '',
            '{client-id}' => $config['client_id'] ?? '',
            '{connection}' => $config['connection'] ?? '',
        ];

        foreach ($placeholders as $placeholder => $value) {
            if (!empty($value)) {
                $url = str_replace($placeholder, $value, $url);
            }
        }

        return $url;
    }

    private function validateProviderConfig(array $config): bool
    {
        $required = ['entity_id', 'sso_url'];

        foreach ($required as $field) {
            if (empty($config[$field])) {
                $this->logError('Missing required SAML field: {field}', ['field' => $field]);
                return false;
            }
        }

        // Validate certificate format if provided
        if (!empty($config['x509cert']) && !$this->isValidX509Certificate($config['x509cert'])) {
            $this->logError('Invalid X.509 certificate format');
            return false;
        }

        return true;
    }

    private function isValidX509Certificate(string $cert): bool
    {
        // Remove any whitespace and check if it looks like a certificate
        $cert = trim($cert);

        if (empty($cert)) {
            return false;
        }

        // Check if it's already in proper format or just the certificate data
        if (strpos($cert, '-----BEGIN CERTIFICATE-----') === false) {
            // Assume it's just the certificate data without headers/footers
            $cert = "-----BEGIN CERTIFICATE-----\n" .
                    chunk_split($cert, 64, "\n") .
                    "-----END CERTIFICATE-----";
        }

        // Try to parse the certificate
        $resource = openssl_x509_read($cert);
        if ($resource !== false) {
            return true;
        }

        return false;
    }

    public function validatePermissionTemplateMapping(): array
    {
        $errors = [];
        $mapping = $this->configManager->get('saml', 'permission_template_mapping', []);

        // Empty mapping is allowed - will use default_permission_template fallback
        if (empty($mapping)) {
            $defaultTemplate = $this->configManager->get('saml', 'default_permission_template', '');
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
        return (bool)$this->configManager->get('saml', 'enabled', false);
    }

    /**
     * Generate OneLogin SAML settings array from configuration
     */
    public function generateOneLoginSettings(string $providerId): array
    {
        $providerConfig = $this->getProviderConfig($providerId);
        $spConfig = $this->getServiceProviderConfig();

        if (!$providerConfig) {
            throw new \RuntimeException("Provider {$providerId} not found or invalid");
        }

        return [
            'sp' => [
                'entityId' => $spConfig['entity_id'],
                'assertionConsumerService' => [
                    'url' => $spConfig['assertion_consumer_service_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => $spConfig['single_logout_service_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => $spConfig['name_id_format'],
                'x509cert' => $spConfig['x509cert'],
                'privateKey' => $spConfig['private_key'],
            ],
            'idp' => [
                'entityId' => $providerConfig['entity_id'],
                'singleSignOnService' => [
                    'url' => $providerConfig['sso_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => $providerConfig['slo_url'] ?? '',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $providerConfig['x509cert'] ?? '',
                'x509certMulti' => $providerConfig['x509cert_multi'] ?? [],
            ],
            'security' => array_merge([
                'nameIdEncrypted' => false,
                'authnRequestsSigned' => !empty($spConfig['private_key']),
                'logoutRequestSigned' => !empty($spConfig['private_key']),
                'logoutResponseSigned' => !empty($spConfig['private_key']),
                'signMetadata' => !empty($spConfig['private_key']),
                'wantAssertionsSigned' => !empty($providerConfig['x509cert']),
                'wantNameId' => true,
                'wantAssertionsEncrypted' => false,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => false,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                'relaxDestinationValidation' => true, // Allow HTTP/HTTPS mismatch for reverse proxy setups
                'destinationStrictlyMatches' => false,
            ], $providerConfig['security'] ?? []),
        ];
    }
}
