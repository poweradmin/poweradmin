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
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\Application\Dnssec\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\DnsRecord;
use Poweradmin\Domain\Dnssec\DnssecAlgorithm;
use Poweradmin\LegacyUsers;
use Poweradmin\Permission;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once __DIR__ . '/vendor/autoload.php';

class DnsSecController extends BaseController {

    public function run(): void
    {
        if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $zone_id = htmlspecialchars($_GET['id']);
        $perm_view = Permission::getViewPermission($this->db);
        $user_is_zone_owner = LegacyUsers::verify_user_is_owner_zoneid($this->db, $zone_id);

        (LegacyUsers::verify_permission($this->db, 'user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this zone."));
        }

        if (DnsRecord::zone_id_exists($this->db, $zone_id) == "0") {
            $this->showError(_('There is no zone with this ID.'));
        }

        $this->showDnsSecKeys($zone_id);
    }

    public function showDnsSecKeys(string $zone_id): void
    {
        $domain_name = DnsRecord::get_domain_name_by_id($this->db, $zone_id);
        if (preg_match("/^xn--/", $domain_name)) {
            $idn_zone_name = idn_to_utf8($domain_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        $this->render('dnssec.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'domain_type' => DnsRecord::get_domain_type($this->db, $zone_id),
            'keys' => $dnssecProvider->getKeys($domain_name),
            'pdnssec_use' => $this->config('pdnssec_use'),
            'record_count' => DnsRecord::count_zone_records($this->db, $zone_id),
            'zone_id' => $zone_id,
            'zone_template_id' => DnsRecord::get_zone_template($this->db, $zone_id),
            'zone_templates' => ZoneTemplate::get_list_zone_templ($this->db, $_SESSION['userid']),
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
        ]);
    }
}

$controller = new DnsSecController();
$controller->run();
