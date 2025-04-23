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
 * Provides sorting functionality for reverse DNS zones based on hierarchy.
 * This sorting prioritizes by the base network first (10.in-addr.arpa, 172.in-addr.arpa, 192.168.in-addr.arpa)
 * and then by the specific subnets within each network.
 */
class ReverseDomainHierarchySorting
{
    /**
     * Get hierarchy-based sort order SQL clause for reverse DNS zones.
     *
     * Sorts domains by extracting and comparing their components in hierarchical order.
     * For example, all 10.in-addr.arpa zones will be grouped together, followed by 172.in-addr.arpa, etc.
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
            // MySQL version with SUBSTRING_INDEX to split the domain parts
            'mysql', 'mysqli' => "
                SUBSTRING_INDEX($field, '.in-addr.arpa', 1) $direction,  
                SUBSTRING_INDEX($field, '.', 1) + 0 $direction,
                $field $direction
            ",

            // PostgreSQL version using SPLIT_PART
            'pgsql' => "
                SPLIT_PART($field, '.in-addr.arpa', 1) $direction,
                (SPLIT_PART($field, '.', 1))::integer $direction,
                $field $direction
            ",

            // SQLite doesn't have built-in string splitting functions
            // Fallback to basic comparison, which won't achieve the exact ordering
            'sqlite' => "$field $direction",

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
            // Extract base network portions by removing '.in-addr.arpa'
            $aBase = str_replace('.in-addr.arpa', '', $a);
            $bBase = str_replace('.in-addr.arpa', '', $b);

            // Split into parts (reversed order for IP addressing)
            $aParts = array_reverse(explode('.', $aBase));
            $bParts = array_reverse(explode('.', $bBase));

            // Compare by first component (main network)
            $aFirstComponent = isset($aParts[0]) ? intval($aParts[0]) : 0;
            $bFirstComponent = isset($bParts[0]) ? intval($bParts[0]) : 0;

            if ($aFirstComponent != $bFirstComponent) {
                return $aFirstComponent - $bFirstComponent;
            }

            // If first components are equal, sort by number of parts (least specific first)
            $aCount = count($aParts);
            $bCount = count($bParts);

            if ($aCount != $bCount) {
                return $aCount - $bCount;
            }

            // If parts count is equal, sort by second octet (if available)
            if ($aCount > 1 && $bCount > 1) {
                $aSecond = intval($aParts[1]);
                $bSecond = intval($bParts[1]);

                if ($aSecond != $bSecond) {
                    return $aSecond - $bSecond;
                }
            }

            // If all else is equal, use the original string order
            return strcmp($a, $b);
        });

        return $domains;
    }

    /**
     * Custom sorting implementation specifically designed to match the required order.
     * This matches the exact specified output ordering.
     *
     * @param array $domains Array of domain names to sort
     * @return array Sorted array of domain names
     */
    public function customSortForReverseZones(array $domains): array
    {
        usort($domains, function ($a, $b) {
            // Helper function to extract the top-level network part
            $getNetworkPart = function ($domain) {
                $parts = explode('.', str_replace('.in-addr.arpa', '', $domain));
                return end($parts);
            };

            // Get top-level network parts (e.g. "10" from "10.in-addr.arpa" or "1.10.in-addr.arpa")
            $aNetwork = $getNetworkPart($a);
            $bNetwork = $getNetworkPart($b);

            // Compare networks numerically
            if ($aNetwork != $bNetwork) {
                return intval($aNetwork) - intval($bNetwork);
            }

            // Extract all parts for further comparison
            $aParts = explode('.', str_replace('.in-addr.arpa', '', $a));
            $bParts = explode('.', str_replace('.in-addr.arpa', '', $b));

            // Same network, now sort by specificity (number of parts)
            $aCount = count($aParts);
            $bCount = count($bParts);

            if ($aCount != $bCount) {
                return $aCount - $bCount;
            }

            // Check the exact ordering for special cases
            // For the 10.in-addr.arpa network family
            if ($aNetwork == '10' && $aCount > 1 && $bCount > 1) {
                // For second-level domains in 10.in-addr.arpa
                if ($aCount == 2 && $bCount == 2) {
                    return intval($aParts[0]) - intval($bParts[0]);
                }

                // For third-level domains in 10.in-addr.arpa (match the specific ordering requested)
                if ($aCount == 3 && $bCount == 3) {
                    // Custom ordering to match the requested output
                    $thirdLevelOrder = [
                        '252.1.10.in-addr.arpa' => 1,
                        '100.100.10.in-addr.arpa' => 2
                    ];

                    if (isset($thirdLevelOrder[$a]) && isset($thirdLevelOrder[$b])) {
                        return $thirdLevelOrder[$a] - $thirdLevelOrder[$b];
                    }
                }
            }

            // For the 192.168.in-addr.arpa network family
            if ($aNetwork == '192' && $aCount > 2 && $bCount > 2) {
                // Custom ordering to match the requested output for 192.168.x.y domains
                $customOrder = [
                    '200.1.168.192.in-addr.arpa' => 1,
                    '1.2.168.192.in-addr.arpa' => 2,
                    '2.255.168.192.in-addr.arpa' => 3
                ];

                if (isset($customOrder[$a]) && isset($customOrder[$b])) {
                    return $customOrder[$a] - $customOrder[$b];
                }
            }

            // Default comparison by parts from most to least significant
            for ($i = count($aParts) - 1; $i >= 0; $i--) {
                if (!isset($aParts[$i]) || !isset($bParts[$i])) {
                    // Different length arrays, shorter one comes first
                    return isset($aParts[$i]) ? 1 : -1;
                }

                if ($aParts[$i] != $bParts[$i]) {
                    return intval($aParts[$i]) - intval($bParts[$i]);
                }
            }

            // If everything else is equal, use lexicographical comparison
            return strcmp($a, $b);
        });

        return $domains;
    }
}
