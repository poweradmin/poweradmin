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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;
use RuntimeException;

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

            $config = $this->processUrlTemplates($providers[$providerId]);

            $error = $this->describeConfigError($config);
            if ($error !== null) {
                $this->logError('Invalid SAML configuration for provider {provider}: {error}', [
                    'provider' => $providerId,
                    'error' => $error,
                ]);
                return null;
            }

            return $config;
        } catch (\Exception $e) {
            $this->logError('Error getting SAML provider config for {provider}: {error}', [
                'provider' => $providerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Return a human-readable reason why the SAML provider configuration is
     * unusable, or null if it passes all checks. Mirrors the validation
     * performed by getProviderConfig() so callers can surface the actual cause
     * (missing entity_id, malformed x509cert, ...) to end users instead of a
     * generic "not found or not configured" message.
     */
    public function describeProviderConfigError(string $providerId): ?string
    {
        $providers = $this->configManager->get('saml', 'providers', []);

        if (!isset($providers[$providerId])) {
            return sprintf("provider '%s' is not defined in saml.providers", $providerId);
        }

        return $this->describeConfigError($this->processUrlTemplates($providers[$providerId]));
    }

    private function describeConfigError(array $config): ?string
    {
        foreach (['entity_id', 'sso_url'] as $field) {
            if (empty($config[$field])) {
                return sprintf("missing required field '%s'", $field);
            }
        }

        if (!empty($config['x509cert']) && !$this->isValidX509Certificate($config['x509cert'])) {
            return "x509cert is not a valid X.509 certificate";
        }

        return null;
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
            'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'x509cert' => '',
            'private_key' => '',
        ];

        // Derived only where the operator gave no URL: deriving needs
        // interface.application_url, which an explicit sp config does not require.
        $derived = [
            'entity_id' => fn(): string => $this->generateDefaultEntityId(),
            'assertion_consumer_service_url' => fn(): string => $this->generateDefaultAcsUrl(),
            'single_logout_service_url' => fn(): string => $this->generateDefaultSloUrl(),
        ];

        foreach ($derived as $key => $generate) {
            if (!array_key_exists($key, $spConfig)) {
                $defaults[$key] = $generate();
            }
        }

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
        $configuredUrl = $this->configManager->get('interface', 'application_url', '');
        if (!empty($configuredUrl)) {
            return rtrim($configuredUrl, '/');
        }

        // Legacy, undocumented backstop kept so operators who set it manually
        // are not silently broken.
        $configuredBaseUrl = $this->configManager->get('interface', 'base_url', '');
        if (!empty($configuredBaseUrl)) {
            return rtrim($configuredBaseUrl, '/');
        }

        // The entityID/ACS/SLO advertised to the IdP are never derived from the request:
        // SERVER_NAME follows the client Host header under FrankenPHP and Apache defaults.
        throw new RuntimeException(
            'interface.application_url must be configured before SAML can be used: it defines the entityID and ACS URL advertised to the identity provider.'
        );
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

    private function isValidX509Certificate(string $cert): bool
    {
        $cert = trim($cert);

        if (empty($cert)) {
            return false;
        }

        // Accept the cert as-is (covers PEM-with-headers, including any line
        // ending style openssl already understands).
        if (@openssl_x509_read($cert) !== false) {
            return true;
        }

        // The headerless format admins typically paste from IdP downloads has
        // a base64 body with arbitrary whitespace. Strip everything that isn't
        // base64 and rewrap so we don't double-fold lines that already had
        // CRLF/LF line breaks (closes #1218).
        $stripped = preg_replace('/[^A-Za-z0-9+\/=]/', '', $cert) ?? '';
        if ($stripped === '') {
            return false;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n" .
               chunk_split($stripped, 64, "\n") .
               "-----END CERTIFICATE-----\n";

        return @openssl_x509_read($pem) !== false;
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

        if (!is_array($mapping)) {
            return ["Invalid mapping: permission_template_mapping must be an array"];
        }

        foreach ($mapping as $group => $template) {
            // PHP casts numeric array keys to int, so an IdP group id such as
            // '1001' arrives here as an integer. UserProvisioningService casts
            // it back the same way when matching.
            if ((string)$group === '') {
                $errors[] = "Invalid mapping: empty group name";
                continue;
            }

            if (!is_string($template) || trim($template) === '') {
                $errors[] = sprintf("Invalid mapping for group '%s': template name must be a non-empty string", (string)$group);
            }
        }

        return $errors;
    }

    /**
     * Auto-provisioning aborts for any user who matches no group mapping when
     * there is no default template to fall back on, so the login fails with a
     * generic error. Reported to superusers on the dashboard.
     */
    public function isAutoProvisioningTemplateMissing(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!$this->configManager->get('saml', 'auto_provision', true)) {
            return false;
        }

        return empty($this->configManager->get('saml', 'default_permission_template', ''));
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
