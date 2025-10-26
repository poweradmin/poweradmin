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
 * DNS Wizard Registry
 *
 * Central registry for all available DNS wizards. Manages wizard lifecycle,
 * availability, and provides access to wizard instances.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
class WizardRegistry
{
    private ConfigurationInterface $config;
    private array $wizards = [];
    private array $wizardClasses = [
        'DMARC' => 'Poweradmin\Domain\Service\DnsWizard\DMARCWizard',
        'SPF' => 'Poweradmin\Domain\Service\DnsWizard\SPFWizard',
        'DKIM' => 'Poweradmin\Domain\Service\DnsWizard\DKIMWizard',
        'CAA' => 'Poweradmin\Domain\Service\DnsWizard\CAAWizard',
        'TLSA' => 'Poweradmin\Domain\Service\DnsWizard\TLSAWizard',
        'SRV' => 'Poweradmin\Domain\Service\DnsWizard\SRVWizard',
    ];

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
     * Check if wizards are enabled in configuration
     *
     * @return bool True if wizards are enabled
     */
    public function isEnabled(): bool
    {
        try {
            $wizardConfig = $this->config->getGroup('dns_wizards');
            return isset($wizardConfig['enabled']) && $wizardConfig['enabled'] === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get list of available wizard types
     *
     * Returns only wizards that are configured to be available.
     *
     * @return array Array of wizard type identifiers (e.g., ['DMARC', 'SPF', 'DKIM'])
     */
    public function getAvailableWizardTypes(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $wizardConfig = $this->config->getGroup('dns_wizards');
        $availableTypes = $wizardConfig['available_types'] ?? [];

        // Filter to only include wizards that have been implemented
        return array_filter($availableTypes, function ($type) {
            return isset($this->wizardClasses[$type]);
        });
    }

    /**
     * Get a wizard instance by type
     *
     * @param string $type Wizard type identifier (e.g., 'DMARC', 'SPF')
     * @return DnsWizardInterface|null Wizard instance or null if not found
     * @throws \RuntimeException If wizard class doesn't exist or isn't available
     */
    public function getWizard(string $type): ?DnsWizardInterface
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('DNS wizards are not enabled in configuration');
        }

        if (!$this->isWizardAvailable($type)) {
            throw new \RuntimeException(sprintf('Wizard type "%s" is not available', $type));
        }

        // Return cached instance if exists
        if (isset($this->wizards[$type])) {
            return $this->wizards[$type];
        }

        // Create new wizard instance
        $className = $this->wizardClasses[$type];

        if (!class_exists($className)) {
            throw new \RuntimeException(sprintf('Wizard class "%s" not found', $className));
        }

        $wizard = new $className($this->config);

        if (!$wizard instanceof DnsWizardInterface) {
            throw new \RuntimeException(sprintf('Wizard class "%s" must implement DnsWizardInterface', $className));
        }

        // Cache the instance
        $this->wizards[$type] = $wizard;

        return $wizard;
    }

    /**
     * Check if a specific wizard type is available
     *
     * @param string $type Wizard type identifier
     * @return bool True if wizard is available
     */
    public function isWizardAvailable(string $type): bool
    {
        return in_array($type, $this->getAvailableWizardTypes(), true);
    }

    /**
     * Get all available wizard instances
     *
     * @return array Array of wizard instances keyed by type
     */
    public function getAllWizards(): array
    {
        $wizards = [];
        foreach ($this->getAvailableWizardTypes() as $type) {
            try {
                $wizards[$type] = $this->getWizard($type);
            } catch (\RuntimeException $e) {
                // Skip wizards that can't be instantiated
                continue;
            }
        }
        return $wizards;
    }

    /**
     * Get wizard metadata for UI display
     *
     * Returns basic information about all available wizards without instantiating them.
     *
     * @return array Array of wizard metadata
     */
    public function getWizardMetadata(): array
    {
        $metadata = [];

        foreach ($this->getAvailableWizardTypes() as $type) {
            try {
                $wizard = $this->getWizard($type);
                $metadata[$type] = [
                    'type' => $wizard->getWizardType(),
                    'name' => $wizard->getDisplayName(),
                    'description' => $wizard->getDescription(),
                    'recordType' => $wizard->getRecordType(),
                ];
            } catch (\RuntimeException $e) {
                // Skip wizards that can't be instantiated
                continue;
            }
        }

        return $metadata;
    }

    /**
     * Register a custom wizard class
     *
     * Allows plugins or extensions to add custom wizard implementations.
     *
     * @param string $type Wizard type identifier
     * @param string $className Fully qualified class name
     * @return void
     */
    public function registerWizard(string $type, string $className): void
    {
        $this->wizardClasses[$type] = $className;

        // Clear cached instance if exists
        if (isset($this->wizards[$type])) {
            unset($this->wizards[$type]);
        }
    }
}
