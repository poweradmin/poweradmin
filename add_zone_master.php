<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
 * Script that handles requests to add new master zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\Application\Services\DnssecService;
use Poweradmin\BaseController;
use Poweradmin\Dns;
use Poweradmin\DnsRecord;
use Poweradmin\Infrastructure\Dnssec\PdnsUtilProvider;
use Poweradmin\Logger;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/messages.inc.php';

class AddZoneMasterController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a master zone."));

        if ($this->isPost()) {
            $this->addZone();
        } else {
            $this->showForm();
        }
    }

    private function addZone()
    {
        $v = new Valitron\Validator($_POST);
        $v->rules([
            'required' => ['domain', 'dom_type', 'owner', 'zone_template'],
        ]);
        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        $pdnssec_use = $this->config('pdnssec_use');
        $dns_third_level_check = $this->config('dns_third_level_check');

        $zone = idn_to_ascii(trim($_POST['domain']), IDNA_NONTRANSITIONAL_TO_ASCII);
        $dom_type = $_POST["dom_type"];
        $owner = $_POST['owner'];
        $zone_template = $_POST['zone_template'] ?? "none";

        if (!Dns::is_valid_hostname_fqdn($zone, 0)) {
            $this->setMessage('add_zone_master', 'error', _('Invalid hostname.'));
            $this->showForm();
        } elseif ($dns_third_level_check && DnsRecord::get_domain_level($zone) > 2 && DnsRecord::domain_exists(DnsRecord::get_second_level_domain($zone))) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif (DnsRecord::domain_exists($zone) || DnsRecord::record_name_exists($zone)) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif (DnsRecord::add_domain($zone, $owner, $dom_type, '', $zone_template)) {
            $this->setMessage('list_zones', 'success', _('Zone has been added successfully.'));

            $zone_id = DnsRecord::get_zone_id_from_name($zone);
            Logger::log_info(sprintf('client_ip:%s user:%s operation:add_zone zone:%s zone_type:%s zone_template:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $zone, $dom_type, $zone_template), $zone_id);

            if ($pdnssec_use) {
                if (isset($_POST['dnssec'])) {
                    $provider = new PdnsUtilProvider();
                    $service = new DnssecService($provider);
                    $service->secureZone($zone);
                }

                $provider = new PdnsUtilProvider();
                $service = new DnssecService($provider);
                $service->rectifyZone($zone_id);
            }

            $this->redirect('list_zones.php');
        }
    }

    private function showForm()
    {
        $perm_view_others = do_hook('verify_permission', 'user_view_others');

        $this->render('add_zone_master.html', [
            'perm_view_others' => $perm_view_others,
            'session_user_id' => $_SESSION['userid'],
            'available_zone_types' => array("MASTER", "NATIVE"),
            'users' => do_hook('show_users'),
            'zone_templates' => ZoneTemplate::get_list_zone_templ($_SESSION['userid']),
            'iface_zone_type_default' => $this->config('iface_zone_type_default'),
            'pdnssec_use' => $this->config('pdnssec_use'),
        ]);
    }
}

$controller = new AddZoneMasterController();
$controller->run();
