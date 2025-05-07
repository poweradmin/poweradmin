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

/**
 * Script that displays reverse zone list
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\ZoneService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;

class ListReverseZonesController extends BaseController
{
    private ZoneService $zoneService;
    private ReverseZoneSorting $reverseZoneSorting;

    public function run(): void
    {
        $perm_view_zone_own = UserManager::verifyPermission($this->db, 'zone_content_view_own');
        $perm_view_zone_others = UserManager::verifyPermission($this->db, 'zone_content_view_others');

        $permission_check = !($perm_view_zone_own || $perm_view_zone_others);
        $this->checkCondition($permission_check, _('You do not have sufficient permissions to view this page.'));

        // Initialize repository and services
        $zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $this->zoneService = new ZoneService($zoneRepository);
        $this->reverseZoneSorting = new ReverseZoneSorting();

        $this->listReverseZones();
    }

    private function listReverseZones(): void
    {
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $iface_zonelist_serial = $this->config->get('interface', 'display_serial_in_zone_list', false);
        $iface_zonelist_template = $this->config->get('interface', 'display_template_in_zone_list', false);
        // Get default rows per page from config
        $default_rowamount = $this->config->get('interface', 'rows_per_page', 10);

        // Create pagination service and get user preference
        $paginationService = new PaginationService();
        $iface_rowamount = $paginationService->getUserRowsPerPage($default_rowamount);

        $row_start = 0;
        if (isset($_GET['start'])) {
            $start = (int)htmlspecialchars($_GET['start']);
            $row_start = ($start - 1) * $iface_rowamount;
        }

        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);

        // Set count_zones_edit to at least 1 to ensure checkboxes are displayed
        // This is needed because in list_reverse_zones.html the checkboxes are conditionally displayed
        // based on count_zones_edit > 0
        $zoneCountService = new ZoneCountService($this->db, $this->getConfig());
        $count_zones_view = $zoneCountService->countZones($perm_view);
        $count_zones_edit = max(1, $zoneCountService->countZones($perm_edit));

        list($zone_sort_by, $zone_sort_direction) = $this->getZoneSortOrder('zone_sort_by', ['name', 'type', 'count_records', 'owner']);

        if ($perm_view == 'none') {
            $this->showError(_('You do not have the permission to see any zones.'));
        }

        // Get the reverse zone filter type from the request
        $reverse_zone_type = 'all';
        if (isset($_GET['reverse_type'])) {
            $reverse_zone_type = htmlspecialchars($_GET['reverse_type']);
            $_SESSION['reverse_zone_type'] = $reverse_zone_type;
        } elseif (isset($_SESSION['reverse_zone_type'])) {
            $reverse_zone_type = $_SESSION['reverse_zone_type'];
        }

        // Always get the total count of ALL reverse zones (regardless of filter)
        $count_all_reverse_zones = $this->zoneService->countReverseZones(
            $perm_view,
            $_SESSION['userid'],
            'all',  // Always count all reverse zones for the total
            $zone_sort_by,
            $zone_sort_direction
        );

        // Get the actual zones for the current page with efficient DB filtering
        $reverse_zones = $this->zoneService->getReverseZones(
            $perm_view,
            $_SESSION['userid'],
            $reverse_zone_type,
            $row_start,
            $iface_rowamount,
            $zone_sort_by,
            $zone_sort_direction
        );

        // Apply custom sorting when sorting by name
        if ($zone_sort_by === 'name' && !empty($reverse_zones)) {
            // Get the sorting type from configuration (natural is default)
            $sort_type = $this->config->get('interface', 'reverse_zone_sort', 'natural');

            // Extract just the names for sorting
            $zone_names = array_map(function ($zone) {
                return $zone['name'];
            }, $reverse_zones);

            // Sort the names using the configured sorting method
            $sorted_names = $this->reverseZoneSorting->sortDomains($zone_names, $sort_type);

            // Reorder the zones array based on the sorted names
            $sorted_zones = [];
            foreach ($sorted_names as $name) {
                foreach ($reverse_zones as $zone) {
                    if ($zone['name'] === $name) {
                        $sorted_zones[] = $zone;
                        break;
                    }
                }
            }

            // Replace the original zones with the sorted ones
            $reverse_zones = $sorted_zones;
        }

        // Get counts for each type
        $count_ipv4_zones = $this->zoneService->countReverseZones(
            $perm_view,
            $_SESSION['userid'],
            'ipv4',
            $zone_sort_by,
            $zone_sort_direction
        );

        $count_ipv6_zones = $this->zoneService->countReverseZones(
            $perm_view,
            $_SESSION['userid'],
            'ipv6',
            $zone_sort_by,
            $zone_sort_direction
        );

        // Get associated forward zones (if needed)
        $associatedForwardZones = $this->getAssociatedForwardZones($reverse_zones);

        $this->render('list_reverse_zones.html', [
            'zones' => $reverse_zones,
            'count_zones_view' => $count_zones_view,
            'count_zones_edit' => $count_zones_edit,
            'iface_rowamount' => $iface_rowamount,
            'zone_sort_by' => $zone_sort_by,
            'zone_sort_direction' => $zone_sort_direction,
            'iface_zonelist_serial' => $iface_zonelist_serial,
            'iface_zonelist_template' => $iface_zonelist_template,
            'pdnssec_use' => $pdnssec_use,
            'pagination' => $this->createAndPresentPagination($count_all_reverse_zones, $iface_rowamount),
            'session_userlogin' => $_SESSION['userlogin'],
            'perm_edit' => $perm_edit,
            'perm_zone_master_add' => UserManager::verifyPermission($this->db, 'zone_master_add'),
            'perm_zone_slave_add' => UserManager::verifyPermission($this->db, 'zone_slave_add'),
            'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser'),
            'whois_enabled' => $this->config->get('whois', 'enabled', false),
            'rdap_enabled' => $this->config->get('rdap', 'enabled', false),
            'reverse_zone_type' => $reverse_zone_type,
            'count_ipv4_zones' => $count_ipv4_zones,
            'count_ipv6_zones' => $count_ipv6_zones,
            'count_all_reverse_zones' => $count_all_reverse_zones,
            'associated_forward_zones' => $associatedForwardZones,
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);

        $paginationUrl = 'index.php?page=list_reverse_zones&start={PageNumber}';

        // Add reverse_type parameter if it exists
        if (isset($_GET['reverse_type'])) {
            $paginationUrl .= '&reverse_type=' . htmlspecialchars($_GET['reverse_type']);
        }

        // Add rows_per_page parameter if it exists
        if (isset($_GET['rows_per_page'])) {
            $paginationUrl .= '&rows_per_page=' . htmlspecialchars($_GET['rows_per_page']);
        }

        $presenter = new PaginationPresenter($pagination, $paginationUrl);

        return $presenter->present();
    }

    /**
     * Get associated forward zones for reverse zones by analyzing PTR records
     * This optimized version fetches all data in a single efficient query with a JOIN
     * instead of using multiple queries in loops.
     *
     * @param array $reverseZones Array of reverse zone data
     * @return array Associative array mapping reverse zone IDs to arrays of forward zone info
     */
    private function getAssociatedForwardZones(array $reverseZones): array
    {
        $associatedZones = [];
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        // Extract all reverse zone IDs
        $reverseZoneIds = array_map(function ($zone) {
            return $zone['id'];
        }, $reverseZones);

        if (empty($reverseZoneIds)) {
            return $associatedZones;
        }

        // Initialize empty entries for all reverse zones
        foreach ($reverseZoneIds as $zoneId) {
            $associatedZones[$zoneId] = [];
        }

        // Build placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($reverseZoneIds), '?'));

        // Single optimized query that joins PTR records with forward zones
        $query = "SELECT 
                    r.domain_id AS reverse_domain_id, 
                    d.id AS forward_domain_id, 
                    d.name AS forward_domain_name,
                    r.content AS ptr_content
                  FROM $records_table r
                  JOIN $domains_table d ON r.content LIKE CONCAT('%', d.name)
                  WHERE r.domain_id IN ($placeholders)
                    AND r.type = 'PTR'
                    AND d.name NOT LIKE '%.arpa'
                  ORDER BY LENGTH(d.name) DESC";

        $stmt = $this->db->prepare($query);

        // Bind all zone IDs
        $paramIndex = 1;
        foreach ($reverseZoneIds as $zoneId) {
            $stmt->bindValue($paramIndex, $zoneId, \PDO::PARAM_INT);
            $paramIndex++;
        }

        $stmt->execute();
        $matches = $stmt->fetchAll();

        // Process results to build the final association map
        // We use a tracking array to ensure we only count each PTR record once for its best match
        $processedPtrs = [];

        foreach ($matches as $match) {
            $reverseDomainId = $match['reverse_domain_id'];
            $forwardDomainId = $match['forward_domain_id'];
            $forwardDomainName = $match['forward_domain_name'];
            $ptrContent = $match['ptr_content'];

            // Create a tracking key for this specific PTR record
            $ptrKey = $reverseDomainId . '-' . $ptrContent;

            // Only process each PTR record once (the first match will be the most specific due to ORDER BY)
            if (isset($processedPtrs[$ptrKey])) {
                continue;
            }

            $processedPtrs[$ptrKey] = true;

            // Create or update the forward zone entry for this reverse zone
            if (!isset($associatedZones[$reverseDomainId][$forwardDomainId])) {
                $associatedZones[$reverseDomainId][$forwardDomainId] = [
                    'id' => $forwardDomainId,
                    'name' => $forwardDomainName,
                    'ptr_records' => 1
                ];
            } else {
                $associatedZones[$reverseDomainId][$forwardDomainId]['ptr_records']++;
            }
        }

        // Convert associative arrays to indexed arrays for consistent output format
        foreach ($reverseZoneIds as $zoneId) {
            if (isset($associatedZones[$zoneId]) && is_array($associatedZones[$zoneId])) {
                $associatedZones[$zoneId] = array_values($associatedZones[$zoneId]);
            } else {
                $associatedZones[$zoneId] = [];
            }
        }

        return $associatedZones;
    }

    public function getZoneSortOrder(string $name, array $allowedValues): array
    {
        $zone_sort_by = 'name';
        $zone_sort_direction = 'ASC';

        if (isset($_GET[$name]) && preg_match("/^[a-z_]+$/", $_GET[$name])) {
            $zone_sort_by = htmlspecialchars($_GET[$name]);
            $_SESSION['list_zone_sort_by'] = htmlspecialchars($_GET[$name]);
        } elseif (isset($_POST[$name]) && preg_match("/^[a-z_]+$/", $_POST[$name])) {
            $zone_sort_by = htmlspecialchars($_POST[$name]);
            $_SESSION['list_zone_sort_by'] = htmlspecialchars($_POST[$name]);
        } elseif (isset($_SESSION['list_zone_sort_by'])) {
            $zone_sort_by = $_SESSION['list_zone_sort_by'];
        }

        if (!in_array($zone_sort_by, $allowedValues)) {
            $zone_sort_by = 'name';
        }

        if (isset($_GET[$name . '_direction']) && in_array(strtoupper($_GET[$name . '_direction']), ['ASC', 'DESC'])) {
            $zone_sort_direction = strtoupper($_GET[$name . '_direction']);
            $_SESSION['list_zone_sort_by_direction'] = strtoupper($_GET[$name . '_direction']);
        } elseif (isset($_POST[$name . '_direction']) && in_array(strtoupper($_POST[$name . '_direction']), ['ASC', 'DESC'])) {
            $zone_sort_direction = strtoupper($_POST[$name . '_direction']);
            $_SESSION['list_zone_sort_by_direction'] = strtoupper($_POST[$name . '_direction']);
        } elseif (isset($_SESSION['list_zone_sort_by_direction'])) {
            $zone_sort_direction = $_SESSION['list_zone_sort_by_direction'];
        }

        return [$zone_sort_by, $zone_sort_direction];
    }
}
