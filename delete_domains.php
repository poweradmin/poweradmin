<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 * Script that handles zones deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\DnsRecord;
use Poweradmin\Logger;
use Poweradmin\Permission;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

class DeleteDomainsController extends BaseController {

    public function run(): void
    {
        $zone_ids = $_POST['zone_id'];
        if (!$zone_ids) {
            header("Location: list_zones.php");
            exit;
        }

        if (isset($_POST['confirm'])) {
            $this->deleteDomains($zone_ids);
        }

        $this->showDomains($zone_ids);
    }

    public function deleteDomains($zone_ids): void
    {
        $deleted_zones = DnsRecord::get_zone_info_from_ids($zone_ids);
        $delete_domains = DnsRecord::delete_domains($zone_ids);

        if ($delete_domains) {
            foreach ($deleted_zones as $deleted_zone) {
                Logger::log_info(sprintf('client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
                    $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                    $deleted_zone['name'], $deleted_zone['type']), $deleted_zone['id']);
            }

            if (count($deleted_zones) == 1) {
                $this->setMessage('list_zones', 'success', _('Zone has been deleted successfully.'));
            } else {
                $this->setMessage('list_zones', 'success', _('Zones have been deleted successfully.'));
            }
            $this->redirect('list_zones.php');
        }
    }

    public function showDomains($zone_ids): void
    {
        $zones = $this->getZoneInfo($zone_ids);
        $this->render('delete_domains.html', [
            'perm_edit' => Permission::getEditPermission(),
            'zones' => $zones,
            'error' => ERR_PERM_DEL_ZONE
        ]);
    }

    private function getZoneInfo($zone_ids): array
    {
        $zones = [];
        foreach ($zone_ids as $zone_id) {
            $zones[$zone_id]['id'] = $zone_id;
            $zones[$zone_id] = DnsRecord::get_zone_info_from_id($zone_id);
            $zones[$zone_id]['owner'] = do_hook('get_fullnames_owners_from_domainid', $zone_id);
            $zones[$zone_id]['is_owner'] = $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

            $zones[$zone_id]['has_supermaster'] = false;
            $zones[$zone_id]['slave_master'] = null;
            if ($zones[$zone_id]['type'] == "SLAVE") {
                $slave_master = DnsRecord::get_domain_slave_master($zone_id);
                $zones[$zone_id]['slave_master'] = $slave_master;
                if (DnsRecord::supermaster_exists($slave_master)) {
                    $zones[$zone_id]['has_supermaster'] = true;
                }
            }
        }
        return $zones;
    }
}

$controller = new DeleteDomainsController();
$controller->run();
