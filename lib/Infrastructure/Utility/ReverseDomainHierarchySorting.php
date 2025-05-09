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

namespace Poweradmin\Infrastructure\Utility;

/**
 * Class ReverseDomainHierarchySorting
 *
 * Provides hierarchical sorting for reverse DNS zones based on network structure.
 * This class creates a sorting order that organizes reverse zones by their network hierarchy,
 * grouping all zones related to the same network together and sorting them logically.
 */
class ReverseDomainHierarchySorting
{
    /**
     * Get hierarchy-based sort order SQL clause for reverse DNS zones.
     *
     * Sorts domains by extracting and comparing their components in hierarchical order,
     * grouping zones by primary networks (e.g., 10.in-addr.arpa, 172.in-addr.arpa, 192.168.in-addr.arpa)
     * and then sorting by subnet specificity within each network.
     *
     * @param string $field The full field name to sort (e.g., "table.name")
     * @param string $dbType The database type ('mysql', 'mysqli', 'pgsql', 'sqlite')
     * @param string $direction Sort direction ('ASC' or 'DESC')
     * @return string SQL ORDER BY clause for hierarchical sorting
     */
    public function getHierarchicalSortOrder(string $field, string $dbType, string $direction = 'ASC'): string
    {
        // Normalize direction
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        // Generate database-specific hierarchical sort
        return match ($dbType) {
            // MySQL version
            'mysql', 'mysqli' => "
                /* First separate IPv4 from IPv6 */
                CASE WHEN $field LIKE '%.in-addr.arpa' THEN 0 ELSE 1 END $direction,
                
                /* For IPv4 zones, extract the main network component */
                SUBSTRING_INDEX(SUBSTRING_INDEX($field, '.in-addr.arpa', 1), '.', -1) + 0 $direction,
                
                /* Sort by specificity (number of parts) */
                (LENGTH($field) - LENGTH(REPLACE($field, '.', ''))) $direction,
                
                /* Natural order for remaining parts */
                $field $direction
            ",

            // PostgreSQL version
            'pgsql' => "
                /* First separate IPv4 from IPv6 */
                CASE WHEN $field LIKE '%.in-addr.arpa' THEN 0 ELSE 1 END $direction,
                
                /* For IPv4 zones, extract the main network component */
                (SPLIT_PART(SPLIT_PART($field, '.in-addr.arpa', 1), '.', 
                    array_length(string_to_array(SPLIT_PART($field, '.in-addr.arpa', 1), '.'), 1)
                ))::integer $direction,
                
                /* Sort by specificity (number of parts) */
                array_length(string_to_array($field, '.'), 1) $direction,
                
                /* Natural order for remaining parts */
                $field $direction
            ",

            // SQLite (limited functionality)
            'sqlite' => "
                /* SQLite has limited string manipulation, use simpler approach */
                LENGTH($field) $direction,
                $field $direction
            ",

            // Fallback for unknown database types
            default => "$field $direction",
        };
    }

    /**
     * PHP-based hierarchical sorting for reverse DNS zones.
     * Use this method when database sorting is not available.
     *
     * @param array $domains Array of domain names to sort
     * @return array Sorted array of domain names
     */
    public function sortDomainsHierarchically(array $domains): array
    {
        usort($domains, function ($a, $b) {
            // First separate IPv4 and IPv6 zones
            $aIsIpv4 = str_contains($a, '.in-addr.arpa');
            $bIsIpv4 = str_contains($b, '.in-addr.arpa');
            $aIsIpv6 = str_contains($a, '.ip6.arpa');
            $bIsIpv6 = str_contains($b, '.ip6.arpa');

            // Sort IPv4 before IPv6
            if ($aIsIpv4 && $bIsIpv6) {
                return -1;
            }
            if ($aIsIpv6 && $bIsIpv4) {
                return 1;
            }

            // For IPv4 reverse zones
            if ($aIsIpv4 && $bIsIpv4) {
                // Extract the parts (removing .in-addr.arpa)
                $aParts = array_reverse(explode('.', str_replace('.in-addr.arpa', '', $a)));
                $bParts = array_reverse(explode('.', str_replace('.in-addr.arpa', '', $b)));

                // Compare by top network component (the most significant octet)
                // Example: For "1.2.10.in-addr.arpa", the top network is "10"
                $aNetwork = isset($aParts[0]) ? (int)$aParts[0] : 0;
                $bNetwork = isset($bParts[0]) ? (int)$bParts[0] : 0;

                if ($aNetwork !== $bNetwork) {
                    return $aNetwork - $bNetwork;
                }

                // Same network, compare by specificity (number of parts)
                // Less specific zones (fewer parts) come first
                $aCount = count($aParts);
                $bCount = count($bParts);

                if ($aCount !== $bCount) {
                    return $aCount - $bCount;
                }

                // Same specificity, compare by parts from most significant to least
                for ($i = 0; $i < $aCount; $i++) {
                    $aValue = isset($aParts[$i]) ? (int)$aParts[$i] : 0;
                    $bValue = isset($bParts[$i]) ? (int)$bParts[$i] : 0;

                    if ($aValue !== $bValue) {
                        return $aValue - $bValue;
                    }
                }
            }

            // For IPv6 reverse zones or default comparison
            return strcmp($a, $b);
        });

        return $domains;
    }
}
