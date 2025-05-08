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

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Application\Service\PowerdnsStatusService;

/**
 * Controller for displaying PowerDNS server status
 *
 * @package Poweradmin\Application\Controller
 */
class PdnsStatusController extends BaseController
{
    private PowerdnsStatusService $statusService;

    /**
     * Constructor
     *
     * @param array $request Request parameters
     */
    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->statusService = new PowerdnsStatusService();
    }

    /**
     * Run the controller
     */
    public function run(): void
    {
        // Check if the PowerDNS status feature is enabled in the config
        if (!$this->config->get('interface', 'show_pdns_status', false)) {
            $this->showError(_('The PowerDNS status feature is disabled in the system configuration.'));
            return;
        }

        // Only allow administrators to view server status
        if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            $this->showError(_('You do not have permission to view PowerDNS server status. Only administrators can access this feature.'));
            return;
        }

        // Check if PowerDNS API is enabled in the config
        if (!$this->statusService->isApiEnabled()) {
            $this->showError(_('The PowerDNS API feature is not configured. Please set the API URL and key in the system configuration.'));
            return;
        }

        $this->showStatus();
    }

    /**
     * Show the PowerDNS server status
     */
    private function showStatus(): void
    {
        $serverStatus = $this->statusService->getServerStatus();

        // Get slave servers if any
        $slaveStatus = [];
        $slaveServers = $this->getSlaveServers();
        if (!empty($slaveServers)) {
            $slaveStatus = $this->statusService->checkSlaveServerStatus($slaveServers);
        }

        $this->render('pdns_status.html', [
            'server_status' => $serverStatus,
            'slave_status' => $slaveStatus,
            'pdns_api_enabled' => $this->statusService->isApiEnabled(),
        ]);
    }

    /**
     * Get the list of slave servers from the supermasters table
     *
     * @return array List of slave server IP addresses
     */
    private function getSlaveServers(): array
    {
        $query = "SELECT ip FROM supermasters GROUP BY ip";
        $result = $this->db->query($query);

        $slaveServers = [];
        if ($result) {
            while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
                $slaveServers[] = $row['ip'];
            }
        }

        return $slaveServers;
    }
}
