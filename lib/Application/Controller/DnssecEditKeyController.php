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

/**
 * Script that handles zone deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;

class DnssecEditKeyController extends BaseController
{

    public function run(): void
    {
        $zone_id = "-1";
        if (isset($_GET['id']) && Validator::is_number($_GET['id'])) {
            $zone_id = htmlspecialchars($_GET['id']);
        }

        $key_id = "-1";
        if (isset($_GET['key_id']) && Validator::is_number($_GET['key_id'])) {
            $key_id = (int)$_GET['key_id'];
        }

        $confirm = "-1";
        if (isset($_GET['confirm']) && Validator::is_number($_GET['confirm'])) {
            $confirm = $_GET['confirm'];
        }

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

        if ($zone_id == "-1") {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $domain_name = $dnsRecord->get_domain_name_by_id($zone_id);

        if ($key_id == "-1") {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
        if (!$dnssecProvider->keyExists($domain_name, $key_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        if ($user_is_zone_owner != "1") {
            $this->showError(_('Failed to delete DNSSEC key.'));
        }

        $key_info = $dnssecProvider->getZoneKey($domain_name, $key_id);

        if ($confirm == '1') {
            if ($key_info[5]) {
                if ($dnssecProvider->deactivateZoneKey($domain_name, $key_id)) {
                    $this->setMessage('dnssec', 'success', _('Zone key has been successfully deactivated.'));
                    $this->redirect('index.php', ['page'=> 'dnssec', 'id' => $zone_id]);
                }
            } else {
                if ($dnssecProvider->activateZoneKey($domain_name, $key_id)) {
                    $this->setMessage('dnssec', 'success', _('Zone key has been successfully activated.'));
                    $this->redirect('index.php', ['page'=> 'dnssec', 'id' => $zone_id]);
                }
            }
        }

        if (str_starts_with($domain_name, "xn--")) {
            $idn_zone_name = idn_to_utf8($domain_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('dnssec_edit_key.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'key_id' => $key_id,
            'key_info' => $dnssecProvider->getZoneKey($domain_name, $key_id),
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'user_is_zone_owner' => $user_is_zone_owner,
            'zone_id' => $zone_id,
        ]);
    }
}
