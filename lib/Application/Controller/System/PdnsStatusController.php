<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Controller\System;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\Backend\PowerdnsStatusService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Dns\SupermasterManager;

/**
 * Renders the PowerDNS server status page from the API's server info and statistics.
 */
class PdnsStatusController extends BaseController
{
    private ?PowerdnsStatusService $statusService = null;
    private ?SupermasterManager $supermasterManager = null;

    private function statusService(): PowerdnsStatusService
    {
        return $this->statusService ??= $this->services()->powerdnsStatusService();
    }

    private function supermasterManager(): SupermasterManager
    {
        return $this->supermasterManager ??= $this->services()->supermasterManager();
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
        if (!$this->hasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            $this->showError(_('You do not have permission to view PowerDNS server status. Only administrators can access this feature.'));
            return;
        }

        // Check if PowerDNS API is enabled in the config
        if (!$this->statusService()->isApiEnabled()) {
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
        $serverStatus = $this->statusService()->getServerStatus();

        // Get slave servers if any
        $slaveStatus = [];
        $slaveServers = $this->supermasterManager()->getSlaveServerIPs();
        if (!empty($slaveServers)) {
            $slaveStatus = $this->statusService()->checkSlaveServerStatus($slaveServers);
        }

        $serverStatus['error'] ??= null;

        $this->render('pdns_status.html', [
            'server_status' => $serverStatus,
            'slave_status' => $slaveStatus,
            'pdns_api_enabled' => $this->statusService()->isApiEnabled(),
        ]);
    }
}
