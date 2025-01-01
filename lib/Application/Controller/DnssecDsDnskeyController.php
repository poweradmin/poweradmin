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
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;

class DnssecDsDnskeyController extends BaseController
{

    public function run(): void
    {
        $pdnssec_use = $this->config('pdnssec_use');

        $zone_id = "-1";
        if (isset($_GET['id']) && Validator::is_number($_GET['id'])) {
            $zone_id = htmlspecialchars($_GET['id']);
        }

        if ($zone_id == "-1") {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

        (UserManager::verify_permission($this->db, 'user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

        $perm_view = Permission::getViewPermission($this->db);

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this zone."));
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if ($dnsRecord->zone_id_exists($zone_id) == "0") {
            $this->showError(_('There is no zone with this ID.'));
        }

        $this->showKeys($zone_id, $pdnssec_use);
    }

    public function showKeys(string $zone_id, $pdnssec_use): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        $domain_name = $dnsRecord->get_domain_name_by_id($zone_id);
        $domain_type = $dnsRecord->get_domain_type($zone_id);
        $record_count = $dnsRecord->count_zone_records($zone_id);
        $zone_template_id = DnsRecord::get_zone_template($this->db, $zone_id);

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
        $dnskey_records = $dnssecProvider->getDnsKeyRecords($domain_name);
        $ds_records = $dnssecProvider->getDsRecords($domain_name);

        if (str_starts_with($domain_name, "xn--")) {
            $idn_zone_name = idn_to_utf8($domain_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('dnssec_ds_dnskey.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'domain_type' => $domain_type,
            'dnskey_records' => $dnskey_records,
            'ds_records' => $ds_records,
            'pdnssec_use' => $pdnssec_use,
            'record_count' => $record_count,
            'zone_id' => $zone_id,
            'zone_template_id' => $zone_template_id,
        ]);
    }
}
