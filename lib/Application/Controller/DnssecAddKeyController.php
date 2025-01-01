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
 * Script that handles requests to add new supermaster servers
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;

class DnssecAddKeyController extends BaseController
{

    public function run(): void
    {
        $zone_id = "-1";
        if (isset($_GET['id']) && Validator::is_number($_GET['id'])) {
            $zone_id = htmlspecialchars($_GET['id']);
        }

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

        if ($user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this zone."));
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if ($dnsRecord->zone_id_exists($zone_id) == "0") {
            $this->showError(_('There is no zone with this ID.'));
        }

        $key_type = "";
        if (isset($_POST['key_type'])) {
            $key_type = $_POST['key_type'];

            if ($key_type != 'ksk' && $key_type != 'zsk') {
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        $bits = "";
        if (isset($_POST["bits"])) {
            $bits = $_POST["bits"];

            $valid_values = array('2048', '1024', '768', '384', '256');
            if (!in_array($bits, $valid_values)) {
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        $algorithm = "";
        if (isset($_POST["algorithm"])) {
            $algorithm = $_POST["algorithm"];

            // To check the supported DNSSEC algorithms in your build of PowerDNS, run pdnsutil list-algorithms.
            $valid_algorithm = array('rsasha1', 'rsasha1-nsec3', 'rsasha256', 'rsasha512', 'ecdsa256', 'ecdsa384', 'ed25519', 'ed448');
            if (!in_array($algorithm, $valid_algorithm)) {
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $domain_name = $dnsRecord->get_domain_name_by_id($zone_id);
        if (isset($_POST["submit"])) {
            $this->validateCsrfToken();

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
            if ($dnssecProvider->addZoneKey($domain_name, $key_type, $bits, $algorithm)) {
                $this->setMessage('dnssec', 'success', _('Zone key has been added successfully.'));
                $this->redirect('index.php', ['page'=> 'dnssec', 'id' => $zone_id]);
            } else {
                $this->setMessage('dnssec_add_key', "error", _('Failed to add new DNSSEC key.'));
            }
        }

        if (str_starts_with($domain_name, "xn--")) {
            $idn_zone_name = idn_to_utf8($domain_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('dnssec_add_key.html', [
            'zone_id' => $zone_id,
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'key_type' => $key_type,
            'bits' => $bits,
            'algorithm' => $algorithm,
            'algorithm_names' => DnssecAlgorithmName::ALGORITHM_NAMES
        ]);
    }
}
