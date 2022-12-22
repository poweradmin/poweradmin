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
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Permission;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once 'inc/toolkit.inc.php';

class DnsSecDsDnsKeyController extends BaseController {

    public function run(): void
    {
        $pdnssec_use = $this->config('pdnssec_use');

        $zone_id = "-1";
        if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
            $zone_id = htmlspecialchars($_GET['id']);
        }

        if ($zone_id == "-1") {
            error(ERR_INV_INPUT);
            include_once('inc/footer.inc.php');
            exit;
        }

        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

        (do_hook('verify_permission', 'user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

        $perm_view = Permission::getViewPermission();

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            error(ERR_PERM_VIEW_ZONE);
            include_once("inc/footer.inc.php");
            exit();
        }

        if (DnsRecord::zone_id_exists($zone_id) == "0") {
            error(ERR_ZONE_NOT_EXIST);
            include_once("inc/footer.inc.php");
            exit();
        }

        $this->showKeys($zone_id, $pdnssec_use);
    }

    public function showKeys(string $zone_id, $pdnssec_use): void
    {
        $domain_name = DnsRecord::get_domain_name_by_id($zone_id);
        $domain_type = DnsRecord::get_domain_type($zone_id);
        $record_count = DnsRecord::count_zone_records($zone_id);
        $zone_templates = ZoneTemplate::get_list_zone_templ($_SESSION['userid']);
        $zone_template_id = DnsRecord::get_zone_template($zone_id);
        $dnskey_records = Dnssec::dnssec_get_dnskey_record($domain_name);
        $ds_records = Dnssec::dnssec_get_ds_records($domain_name);

        $this->render('dnssec_ds_dnskey.html', [
            'domain_name' => $domain_name,
            'domain_type' => $domain_type,
            'dnskey_records' => $dnskey_records,
            'ds_records' => $ds_records,
            'pdnssec_use' => $pdnssec_use,
            'record_count' => $record_count,
            'zone_id' => $zone_id,
            'zone_template_id' => $zone_template_id,
            'zone_templates' => $zone_templates,
        ]);
    }
}

$controller = new DnsSecDsDnsKeyController();
$controller->run();
