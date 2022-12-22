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
 * Script that handles zone deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Logger;
use Poweradmin\Permission;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

class DeleteDomainController extends BaseController
{

    public function run(): void
    {
        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);
        if (!$v->validate()) {
            $this->showErrors($v->errors());
        }

        $zone_id = htmlspecialchars($_GET['id']);

        $perm_edit = Permission::getEditPermission();
        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);
        $this->checkCondition($perm_edit != "all" && ($perm_edit != "own" || !$user_is_zone_owner), ERR_PERM_DEL_ZONE);

        if (isset($_GET['confirm'])) {
            $this->deleteDomain($zone_id);
        } else {
            $this->showDeleteDomain($zone_id);
        }
    }

    private function deleteDomain(string $zone_id)
    {
        $zone_info = DnsRecord::get_zone_info_from_id($zone_id);
        $pdnssec_use = $this->config('pdnssec_use');

        if ($pdnssec_use && $zone_info['type'] == 'MASTER') {
            $zone_name = DnsRecord::get_domain_name_by_id($zone_id);
            if (Dnssec::dnssec_is_zone_secured($zone_name)) {
                Dnssec::dnssec_unsecure_zone($zone_name);
            }
        }

        if (DnsRecord::delete_domain($zone_id)) {
            Logger::log_info(sprintf('client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $zone_info['name'], $zone_info['type']), $zone_id);

            $this->setMessage('list_zones', 'success', SUC_ZONE_DEL);
            $this->redirect('list_zones.php');
        }
    }

    private function showDeleteDomain(string $zone_id)
    {
        $zone_info = DnsRecord::get_zone_info_from_id($zone_id);
        $zone_owners = do_hook('get_fullnames_owners_from_domainid', $zone_id);

        $slave_master_exists = false;
        if ($zone_info['type'] == 'SLAVE') {
            $slave_master = DnsRecord::get_domain_slave_master($zone_id);
            if (DnsRecord::supermaster_exists($slave_master)) {
                $slave_master_exists = true;
            }
        }

        $this->render('delete_domain.html', [
            'zone_id' => $zone_id,
            'zone_info' => $zone_info,
            'zone_owners' => $zone_owners,
            'slave_master_exists' => $slave_master_exists,
        ]);
    }
}

$controller = new DeleteDomainController();
$controller->run();
