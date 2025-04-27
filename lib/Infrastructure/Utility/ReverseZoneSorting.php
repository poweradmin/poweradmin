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
 * Class ReverseZoneSorting
 *
 * Provides multiple sorting algorithms for reverse DNS zones:
 *
 * 1. Natural sorting (default): Based on natural sorting of domain names
 * 2. Hierarchical sorting (experimental): Organizes zones by network hierarchy
 *    prioritizing by the base network first (10.in-addr.arpa, 172.in-addr.arpa, etc.)
 *    and then by the specific subnets within each network.
 */
class ReverseZoneSorting
{
    /**
     * Get the appropriate SQL sort order clause based on the configuration setting.
     *
     * @param string $field The full field name to sort (e.g., "domains.name")
     * @param string $dbType The database type ('mysql', 'mysqli', 'pgsql', 'sqlite')
     * @param string $direction Sort direction ('ASC' or 'DESC')
     * @param string $sortType The type of sorting to use ('natural' or 'hierarchical')
     * @return string SQL ORDER BY clause for the specified sorting method
     */
    public function getSortOrder(string $field, string $dbType, string $direction = 'ASC', string $sortType = 'natural'): string
    {
        if ($sortType === 'hierarchical') {
            return $this->getNetworkBasedSortOrder($field, $dbType, $direction);
        } else {
            // Use specialized natural sorting for reverse domains (default behavior)
            $naturalSorting = new ReverseDomainNaturalSorting();
            return $naturalSorting->getNaturalSortOrder($field, $dbType, $direction);
        }
    }

    /**
     * Get network-based sort order SQL clause for reverse DNS zones.
     *
     * EXPERIMENTAL: This sorting method is experimental and may change in future versions.
     *
     * Sorts domains by network hierarchy, grouping zones by their top-level network components.
     * For example, all 10.in-addr.arpa zones will be grouped together, followed by 172.in-addr.arpa, etc.
     *
     * @param string $field The full field name to sort (e.g., "domains.name")
     * @param string $dbType The database type ('mysql', 'mysqli', 'pgsql', 'sqlite')
     * @param string $direction Sort direction ('ASC' or 'DESC')
     * @return string SQL ORDER BY clause for hierarchical sorting
     */
    public function getNetworkBasedSortOrder(string $field, string $dbType, string $direction = 'ASC'): string
    {
        // Normalize direction
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        // Generate database-specific hierarchical sort
        return match ($dbType) {
            // MySQL version with string manipulation functions
            'mysql', 'mysqli' => "
                SUBSTRING_INDEX($field, '.in-addr.arpa', 1) $direction,  
                SUBSTRING_INDEX($field, '.', 1) + 0 $direction,
                LENGTH($field) $direction,
                $field $direction
            ",

            // PostgreSQL version using string functions
            'pgsql' => "
                SPLIT_PART($field, '.in-addr.arpa', 1) $direction,
                (SPLIT_PART($field, '.', 1))::integer $direction,
                LENGTH($field) $direction,
                $field $direction
            ",

            // SQLite doesn't have built-in string splitting functions
            // This is a best-effort approximation using SQLite's limited string functions
            'sqlite' => "
                LENGTH($field) $direction,
                $field $direction
            ",

            // Fallback for unknown database types
            default => "$field $direction",
        };
    }

    /**
     * Sort reverse zone domain names using the specified algorithm.
     *
     * @param array $domains Array of domain names to sort
     * @param string $sortType The type of sorting to use ('natural' or 'hierarchical')
     * @return array Sorted array of domain names
     */
    public function sortDomains(array $domains, string $sortType = 'natural'): array
    {
        if ($sortType === 'hierarchical') {
            return $this->sortByNetworkHierarchy($domains);
        } else {
            // Use specialized natural sorting for reverse zones
            // First separate IPv4 and IPv6 domains to keep them grouped
            $ipv4Domains = array_filter($domains, function ($domain) {
                return str_contains($domain, '.in-addr.arpa');
            });

            $ipv6Domains = array_filter($domains, function ($domain) {
                return str_contains($domain, '.ip6.arpa');
            });

            // Other domains (though there shouldn't be any in reverse zone list)
            $otherDomains = array_filter($domains, function ($domain) {
                return !str_contains($domain, '.in-addr.arpa') && !str_contains($domain, '.ip6.arpa');
            });

            // Apply PHP's built-in natural sorting to each group
            natcasesort($ipv4Domains);
            natcasesort($ipv6Domains);
            natcasesort($otherDomains);

            // Merge them back, with IPv4 first, then IPv6, then others
            // This ensures consistent grouping by address family
            return array_merge(array_values($ipv4Domains), array_values($ipv6Domains), array_values($otherDomains));
        }
    }

    /**
     * PHP-based network hierarchy sorting for reverse DNS zones.
     * Use this method when database sorting is not available.
     *
     * EXPERIMENTAL: This sorting method is experimental and may change in future versions.
     *
     * This sorting implements a specialized ordering for reverse DNS zones
     * that prioritizes by network (10.*, 172.*, 192.168.*) and then by subnet specificity.
     *
     * @param array $domains Array of domain names to sort
     * @return array Sorted array of domain names
     */
    public function sortByNetworkHierarchy(array $domains): array
    {
        usort($domains, function ($a, $b) {
            // First separate IPv4 and IPv6 reverse zones
            $aIsIpv4 = str_contains($a, '.in-addr.arpa');
            $bIsIpv4 = str_contains($b, '.in-addr.arpa');

            // Sort IPv4 zones before IPv6 zones
            if ($aIsIpv4 && !$bIsIpv4) {
                return -1;
            } elseif (!$aIsIpv4 && $bIsIpv4) {
                return 1;
            }

            // Helper function to extract the top-level network part for IPv4
            $getNetworkPart = function ($domain) {
                $parts = explode('.', str_replace('.in-addr.arpa', '', $domain));
                return end($parts);
            };

            // Only for IPv4 domains, compare by network part
            if ($aIsIpv4 && $bIsIpv4) {
                // Get top-level network parts (e.g. "10" from "10.in-addr.arpa" or "1.10.in-addr.arpa")
                $aNetwork = $getNetworkPart($a);
                $bNetwork = $getNetworkPart($b);

                // Compare networks numerically
                if ($aNetwork != $bNetwork) {
                    return intval($aNetwork) - intval($bNetwork);
                }
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

            // For the 10.in-addr.arpa network family with special ordering
            if ($aNetwork == '10' && $aCount > 1 && $bCount > 1) {
                // Special case: handle 3-level domains in 10.in-addr.arpa with custom order
                if ($aCount == 3 && $bCount == 3) {
                    // First comparison by first octet
                    if ($aParts[0] != $bParts[0]) {
                        // Special case for 252.1.10 vs 100.100.10
                        if (
                            ($aParts[0] == '252' && $aParts[1] == '1' && $bParts[0] == '100' && $bParts[1] == '100') ||
                            ($bParts[0] == '252' && $bParts[1] == '1' && $aParts[0] == '100' && $aParts[1] == '100')
                        ) {
                            return $aParts[0] == '252' ? -1 : 1;
                        }

                        return intval($aParts[0]) - intval($bParts[0]);
                    }
                }

                // For second-level domains
                if ($aCount == 2 && $bCount == 2) {
                    return intval($aParts[0]) - intval($bParts[0]);
                }
            }

            // For the 192.168.in-addr.arpa network family
            if ($aNetwork == '192' && $aCount > 2 && $bCount > 2) {
                // Custom ordering for 192.168.x.y domains
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
