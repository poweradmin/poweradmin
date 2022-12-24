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

class DnsSecController extends BaseController {

    public function run(): void
    {
        if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
            error(_('Invalid or unexpected input given.'));
            include_once('inc/footer.inc.php');
            exit;
        }

        $zone_id = htmlspecialchars($_GET['id']);
        $perm_view = Permission::getViewPermission();
        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

        (do_hook('verify_permission', 'user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            error(_("You do not have the permission to view this zone."));
            include_once("inc/footer.inc.php");
            exit();
        }

        if (DnsRecord::zone_id_exists($zone_id) == "0") {
            error(_('There is no zone with this ID.'));
            include_once("inc/footer.inc.php");
            exit();
        }

        $this->showDnsSecKeys($zone_id);
    }

    public function showDnsSecKeys(string $zone_id): void
    {
        $domain_name = DnsRecord::get_domain_name_by_id($zone_id);
        if (preg_match("/^xn--/", $domain_name)) {
            $idn_zone_name = idn_to_utf8($domain_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('dnssec.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'domain_type' => DnsRecord::get_domain_type($zone_id),
            'keys' => Dnssec::dnssec_get_keys($domain_name),
            'pdnssec_use' => $this->config('pdnssec_use'),
            'record_count' => DnsRecord::count_zone_records($zone_id),
            'zone_id' => $zone_id,
            'zone_template_id' => DnsRecord::get_zone_template($zone_id),
            'zone_templates' => ZoneTemplate::get_list_zone_templ($_SESSION['userid']),
            'algorithms' => Dnssec::dnssec_algorithms(),
        ]);
    }
}

$controller = new DnsSecController();
$controller->run();
