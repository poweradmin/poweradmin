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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class RecordTypeService
{
    private ConfigurationInterface $configManager;

    public function __construct(ConfigurationInterface $configManager)
    {
        $this->configManager = $configManager;
    }

    /**
     * Get all record types.
     *
     * @return array
     */
    public function getAllTypes(): array
    {
        $configuredDomainTypes = $this->configManager->get('dns', 'domain_record_types');
        $configuredReverseTypes = $this->configManager->get('dns', 'reverse_record_types');

        // If both are configured, merge them
        if ($configuredDomainTypes && $configuredReverseTypes) {
            $types = array_merge($configuredDomainTypes, $configuredReverseTypes);
            $types = array_unique($types);
            sort($types);
            return $types;
        }

        // If only domain types are configured, merge with default reverse types
        if ($configuredDomainTypes) {
            $types = array_merge($configuredDomainTypes, RecordType::REVERSE_ZONE_COMMON_RECORDS);
            $types = array_unique($types);
            sort($types);
            return $types;
        }

        // If only reverse types are configured, merge with default domain types
        if ($configuredReverseTypes) {
            $types = array_merge(RecordType::DOMAIN_ZONE_COMMON_RECORDS, $configuredReverseTypes);
            $types = array_unique($types);
            sort($types);
            return $types;
        }

        // If nothing is configured, use all defaults
        $types = array_merge(
            RecordType::DOMAIN_ZONE_COMMON_RECORDS,
            RecordType::REVERSE_ZONE_COMMON_RECORDS,
            RecordType::DNSSEC_TYPES,
            RecordType::LESS_COMMON_RECORDS
        );
        $types = array_unique($types);
        sort($types);
        return $types;
    }

    /**
     * Get domain zone record types.
     *
     * @param bool $isDnsSecEnabled
     * @return array
     */
    public function getDomainZoneTypes(bool $isDnsSecEnabled): array
    {
        $configuredDomainTypes = $this->configManager->get('dns', 'domain_record_types');

        if ($configuredDomainTypes) {
            return $isDnsSecEnabled ?
                $this->mergeDnsSecTypes($configuredDomainTypes, true) :
                $configuredDomainTypes;
        }

        $types = array_merge(RecordType::DOMAIN_ZONE_COMMON_RECORDS, RecordType::LESS_COMMON_RECORDS);
        return $this->mergeDnsSecTypes($types, $isDnsSecEnabled);
    }

    /**
     * Get reverse zone record types.
     *
     * @param bool $isDnsSecEnabled
     * @return array
     */
    public function getReverseZoneTypes(bool $isDnsSecEnabled): array
    {
        $configuredReverseTypes = $this->configManager->get('dns', 'reverse_record_types');

        if ($configuredReverseTypes) {
            return $isDnsSecEnabled ?
                $this->mergeDnsSecTypes($configuredReverseTypes, true) :
                $configuredReverseTypes;
        }

        $types = RecordType::REVERSE_ZONE_COMMON_RECORDS;
        return $this->mergeDnsSecTypes($types, $isDnsSecEnabled);
    }

    /**
     * Merge DNSSEC types if enabled.
     *
     * @param array $types
     * @param bool $isDnsSecEnabled
     * @return array
     */
    private function mergeDnsSecTypes(array $types, bool $isDnsSecEnabled): array
    {
        if ($isDnsSecEnabled) {
            $types = array_merge($types, RecordType::DNSSEC_TYPES);
        }
        sort($types);
        return $types;
    }
}
