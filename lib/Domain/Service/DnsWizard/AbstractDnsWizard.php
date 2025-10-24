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

namespace Poweradmin\Domain\Service\DnsWizard;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Abstract base class for DNS Record Wizards
 *
 * Provides common functionality and shared logic for all DNS wizard implementations.
 * Concrete wizard classes (DMARCWizard, SPFWizard, etc.) should extend this class.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
abstract class AbstractDnsWizard implements DnsWizardInterface
{
    protected ConfigurationInterface $config;
    protected string $recordType;
    protected string $wizardType;
    protected string $displayName;
    protected string $description;
    protected bool $supportsTwoModes = false;

    /**
     * Constructor
     *
     * @param ConfigurationInterface $config Application configuration
     */
    public function __construct(ConfigurationInterface $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getRecordType(): string
    {
        return $this->recordType;
    }

    /**
     * {@inheritdoc}
     */
    public function getWizardType(): string
    {
        return $this->wizardType;
    }

    /**
     * {@inheritdoc}
     */
    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTwoModes(): bool
    {
        return $this->supportsTwoModes;
    }

    /**
     * {@inheritdoc}
     */
    public function getPreview(array $formData): string
    {
        $validation = $this->validate($formData);

        if (!$validation['valid']) {
            return 'Invalid form data. Please correct errors before previewing.';
        }

        try {
            $record = $this->generateRecord($formData);
            return $this->formatPreview($record);
        } catch (\Exception $e) {
            return 'Error generating preview: ' . $e->getMessage();
        }
    }

    /**
     * Format a DNS record for preview display
     *
     * @param array $record The generated record data
     * @return string Formatted preview string
     */
    protected function formatPreview(array $record): string
    {
        $preview = sprintf(
            "Name: %s\nType: %s\nContent: %s\nTTL: %d",
            $record['name'] ?? '@',
            $record['type'] ?? $this->recordType,
            $record['content'] ?? '',
            $record['ttl'] ?? 3600
        );

        if (isset($record['prio']) && $record['prio'] > 0) {
            $preview .= sprintf("\nPriority: %d", $record['prio']);
        }

        return $preview;
    }

    /**
     * Get default TTL from configuration
     *
     * @return int Default TTL value
     */
    protected function getDefaultTTL(): int
    {
        return (int) $this->config->get('dns', 'ttl', 3600);
    }

    /**
     * Sanitize user input
     *
     * @param string $input User input to sanitize
     * @return string Sanitized input
     */
    protected function sanitizeInput(string $input): string
    {
        return trim($input);
    }

    /**
     * Validate TTL value
     *
     * @param mixed $ttl TTL value to validate
     * @return array Validation result
     */
    protected function validateTTL($ttl): array
    {
        $errors = [];

        if (!is_numeric($ttl)) {
            $errors[] = 'TTL must be a numeric value';
            return ['valid' => false, 'errors' => $errors];
        }

        $ttl = (int) $ttl;

        if ($ttl < 0) {
            $errors[] = 'TTL cannot be negative';
        }

        if ($ttl > 2147483647) { // Max 32-bit signed integer
            $errors[] = 'TTL value is too large (max: 2147483647)';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate domain name format
     *
     * @param string $domain Domain name to validate
     * @return bool True if valid
     */
    protected function isValidDomain(string $domain): bool
    {
        // Basic domain validation
        // Allow FQDN with trailing dot, subdomain, or @ for zone apex
        if ($domain === '@' || $domain === '') {
            return true;
        }

        // Remove trailing dot if present (FQDN)
        $domain = rtrim($domain, '.');

        // Check basic format
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $domain);
    }

    /**
     * Validate email address format (DNS style)
     *
     * @param string $email Email address to validate
     * @return bool True if valid
     */
    protected function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Create a standard validation result array
     *
     * @param bool $valid Whether validation passed
     * @param array $errors List of error messages
     * @param array $warnings List of warning messages
     * @return array Validation result
     */
    protected function createValidationResult(bool $valid, array $errors = [], array $warnings = []): array
    {
        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Abstract methods that must be implemented by concrete wizard classes
     */

    /**
     * {@inheritdoc}
     */
    abstract public function getFormSchema(): array;

    /**
     * {@inheritdoc}
     */
    abstract public function generateRecord(array $formData): array;

    /**
     * {@inheritdoc}
     */
    abstract public function validate(array $formData): array;

    /**
     * {@inheritdoc}
     */
    abstract public function parseExistingRecord(string $content, array $recordData = []): array;
}
