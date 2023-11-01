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
 * Script that handles bulk zone registration
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\Dns;
use Poweradmin\DnsRecord;
use Poweradmin\LegacyLogger;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';
require_once 'inc/messages.inc.php';

class BulkRegistrationController extends BaseController {

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a master zone."));

        if ($this->isPost()) {
            $this->doBulkRegistration();
        } else {
            $this->showBulkRegistrationForm();
        }
    }

    private function doBulkRegistration()
    {
        $v = new Valitron\Validator($_POST);
        $v->rules([
            'required' => ['owner', 'dom_type', 'zone_template', 'domains'],
            'integer' => ['owner'],
        ]);

        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        $domains = $this->getDomains($_POST['domains']);
        $dom_type = $_POST["dom_type"];
        $zone_template = $_POST['zone_template'];

        $failed_domains = [];
        foreach ($domains as $domain) {
            if (!Dns::is_valid_hostname_fqdn($domain, 0)) {
                $failed_domains[] = $domain . " - " . _('Invalid hostname.');
            } elseif (DnsRecord::domain_exists($domain)) {
                $failed_domains[] = $domain . " - " . _('There is already a zone with this name.');
            } elseif (DnsRecord::add_domain($domain, $_POST['owner'], $dom_type, '', $zone_template)) {
                $zone_id = DnsRecord::get_zone_id_from_name($domain);
                LegacyLogger::log_info(sprintf('client_ip:%s user:%s operation:add_zone zone:%s zone_type:%s zone_template:%s',
                    $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                    $domain, $dom_type, $zone_template), $zone_id);
            }
        }

        if (!$failed_domains) {
            $this->setMessage('list_zones', 'success', _('Zones has been added successfully.'));
            $this->redirect('list_zones.php');
        } else {
            $this->setMessage('bulk_registration', 'warn', _('Some zone(s) could not be added.'));
            $this->showBulkRegistrationForm(array_unique($failed_domains));
        }
    }

    private function showBulkRegistrationForm(array $failed_domains = [])
    {
        $this->render('bulk_registration.html', [
            'userid' => $_SESSION['userid'],
            'perm_view_others' => do_hook('verify_permission', 'user_view_others'),
            'iface_zone_type_default' => $this->config('iface_zone_type_default'),
            'available_zone_types' => array("MASTER", "NATIVE"),
            'users' => do_hook('show_users'),
            'zone_templates' => ZoneTemplate::get_list_zone_templ($_SESSION['userid']),
            'failed_domains' => $failed_domains,
        ]);
    }

    public function getDomains($array): array
    {
        $domains = explode("\r\n", $array);
        foreach ($domains as $key => $domain) {
            $domain = idn_to_ascii(trim($domain), IDNA_NONTRANSITIONAL_TO_ASCII);
            if ($domain == '') {
                unset($domains[$key]);
            } else {
                $domains[$key] = $domain;
            }
        }
        return $domains;
    }
}

$controller = new BulkRegistrationController();
$controller->run();

