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

namespace Poweradmin\Domain\Service;

use Exception;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Service for managing DNS zones
 */
class ZoneManagementService
{
    private ZoneRepositoryInterface $zoneRepository;
    private ConfigurationManager $config;
    private PDOCommon $db;

    public function __construct(
        ZoneRepositoryInterface $zoneRepository,
        ConfigurationManager $config,
        object $db
    ) {
        $this->zoneRepository = $zoneRepository;
        $this->config = $config;
        $this->db = $db;
    }

    /**
     * Create a new DNS zone
     *
     * @param string $domain Domain name
     * @param string $type Zone type (MASTER, SLAVE, NATIVE)
     * @param int $owner Owner user ID
     * @param string $slaveMaster Master IP for slave zones
     * @param string $zoneTemplate Zone template to use
     * @param bool $enableDnssec Whether to enable DNSSEC
     * @return array Result array with success status and zone ID or error message
     */
    public function createZone(
        string $domain,
        string $type,
        int $owner,
        string $slaveMaster = '',
        string $zoneTemplate = 'none',
        bool $enableDnssec = false
    ): array {
        // Validate domain name
        $hostnameValidator = new HostnameValidator($this->config);
        if (!$hostnameValidator->isValid($domain)) {
            return ['success' => false, 'message' => 'Invalid domain name'];
        }

        // Check if domain already exists
        $dnsRecord = new DnsRecord($this->db, $this->config);
        if ($dnsRecord->domainExists($domain)) {
            return ['success' => false, 'message' => 'Domain already exists'];
        }

        // Validate zone type
        $validTypes = ['MASTER', 'SLAVE', 'NATIVE'];
        if (!in_array($type, $validTypes)) {
            return [
                'success' => false,
                'message' => 'Invalid zone type. Must be one of: ' . implode(', ', $validTypes)
            ];
        }

        // For SLAVE zones, ensure master IP is provided
        if ($type === 'SLAVE' && empty($slaveMaster)) {
            return ['success' => false, 'message' => 'Master IP address is required for SLAVE zones'];
        }

        error_log(sprintf('[ZoneManagementService] Creating zone: %s, Type: %s, Owner: %s', $domain, $type, $owner));

        // Create the domain using DnsRecord service for now (to maintain compatibility)
        $success = $dnsRecord->addDomain($this->db, $domain, $owner, $type, $slaveMaster, $zoneTemplate);

        if (!$success) {
            return ['success' => false, 'message' => 'Failed to create zone'];
        }

        // Get the ID of the newly created zone
        $zoneId = $this->zoneRepository->getZoneIdByName($domain);

        if (!$zoneId) {
            return ['success' => false, 'message' => 'Failed to retrieve zone ID'];
        }

        // Enable DNSSEC if requested and supported
        if ($enableDnssec) {
            try {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);

                if ($dnssecProvider->isDnssecEnabled()) {
                    $dnssecProvider->secureZone($domain);
                    $dnssecProvider->rectifyZone($domain);
                }
            } catch (Exception $e) {
                error_log('[ZoneManagementService] Failed to secure zone with DNSSEC: ' . $e->getMessage());
                // We don't return an error since the zone was created successfully
            }
        }

        return [
            'success' => true,
            'zone_id' => $zoneId,
            'domain' => $domain,
            'type' => $type
        ];
    }

    /**
     * Update a zone
     *
     * @param int $zoneId Zone ID
     * @param array $updates Array of field => value pairs to update
     * @return array Result array with success status and message
     */
    public function updateZone(int $zoneId, array $updates): array
    {
        // Check if zone exists
        if (!$this->zoneRepository->zoneIdExists($zoneId)) {
            return ['success' => false, 'message' => 'Zone not found'];
        }

        // Update the zone
        $success = $this->zoneRepository->updateZone($zoneId, $updates);

        if (!$success) {
            return ['success' => false, 'message' => 'Failed to update zone'];
        }

        return ['success' => true, 'message' => 'Zone updated successfully'];
    }

    /**
     * Delete a zone
     *
     * @param int $zoneId Zone ID
     * @return array Result array with success status and message
     */
    public function deleteZone(int $zoneId): array
    {
        // Check if zone exists
        if (!$this->zoneRepository->zoneIdExists($zoneId)) {
            return ['success' => false, 'message' => 'Zone not found'];
        }

        // Clean up zone template sync records before deletion
        $syncService = new ZoneTemplateSyncService($this->db, $this->config);
        $syncService->cleanupZoneSyncRecords($zoneId);

        // Delete the zone
        $success = $this->zoneRepository->deleteZone($zoneId);

        if (!$success) {
            return ['success' => false, 'message' => 'Failed to delete zone'];
        }

        return ['success' => true, 'message' => 'Zone deleted successfully'];
    }


    /**
     * Set domain permissions
     *
     * @param int $domainId Domain ID
     * @param int $userId User ID
     * @return array Result array with success status and message
     */
    public function setDomainPermissions(int $domainId, int $userId): array
    {
        // Check if domain exists
        $domain = $this->zoneRepository->getZone($domainId);
        if (!$domain) {
            return ['success' => false, 'message' => 'Domain not found'];
        }

        // Check if user is already an owner
        if ($this->zoneRepository->isUserZoneOwner($domainId, $userId)) {
            return [
                'success' => true,
                'message' => 'User is already an owner of this domain',
                'domain_id' => $domainId,
                'user_id' => $userId
            ];
        }

        // Add the user as an owner of the zone
        $success = $this->zoneRepository->addOwnerToZone($domainId, $userId);

        if (!$success) {
            return ['success' => false, 'message' => 'Failed to set domain permissions'];
        }

        return [
            'success' => true,
            'message' => 'Domain permissions set successfully',
            'domain_id' => $domainId,
            'user_id' => $userId
        ];
    }
}
