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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Zone counting service
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class ZoneCountService
{
    private PDOLayer $db;
    private ConfigurationManager $config;

    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Count zones with filtering options
     *
     * @param string $perm 'all', 'own' uses session 'userid'
     * @param string $letterstart Starting letters to match (single letter or '1' for numbers) [default='all' for no filtering]
     * @param string $zone_type Type of zones to count ['all', 'forward', 'reverse'] [default='forward']
     *
     * @return int Count of zones matched
     */
    public function countZones(string $perm, string $letterstart = 'all', string $zone_type = 'forward'): int
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $tables = $domains_table;
        $query_addon = '';

        if ($perm != "own" && $perm != "all") {
            return 0;
        }

        if ($perm == "own") {
            $query_addon = " AND zones.domain_id = $domains_table.id
                AND zones.owner = " . $this->db->quote($_SESSION['userid'], 'integer');
            $tables .= ', zones';
        }

        // Single letter filter (a through z) or numeric filter (1)
        if ($letterstart !== 'all') {
            if ($letterstart === '1') {
                $db_type = $this->config->get('database', 'type');
                $query_addon .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) " . DbCompat::regexp($db_type) . " '[0-9]'";
            } else {
                $query_addon .= " AND $domains_table.name LIKE " . $this->db->quote($letterstart . "%", 'text') . " ";
            }
        }

        // Add filter for forward/reverse zones
        if ($zone_type == 'forward') {
            $query_addon .= " AND $domains_table.name NOT LIKE '%.in-addr.arpa' AND $domains_table.name NOT LIKE '%.ip6.arpa'";
        } elseif ($zone_type == 'reverse') {
            $query_addon .= " AND ($domains_table.name LIKE '%.in-addr.arpa' OR $domains_table.name LIKE '%.ip6.arpa')";
        }

        $query = "SELECT COUNT($domains_table.id) AS count_zones FROM $tables WHERE 1=1 $query_addon";

        return $this->db->queryOne($query);
    }
}
