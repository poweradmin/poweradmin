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
 * 2. Hierarchical sorting: Organizes zones by network hierarchy
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
            $hierarchicalSorting = new ReverseDomainHierarchySorting();
            return $hierarchicalSorting->getHierarchicalSortOrder($field, $dbType, $direction);
        } else {
            // Use specialized natural sorting for reverse domains (default behavior)
            $naturalSorting = new ReverseDomainNaturalSorting();
            return $naturalSorting->getNaturalSortOrder($field, $dbType, $direction);
        }
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
            $hierarchicalSorting = new ReverseDomainHierarchySorting();
            return $hierarchicalSorting->sortDomainsHierarchically($domains);
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
            // array_merge automatically re-indexes, so array_values is redundant
            return array_merge($ipv4Domains, $ipv6Domains, $otherDomains);
        }
    }
}
