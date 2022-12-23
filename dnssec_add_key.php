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
 * Script that handles requests to add new supermaster servers
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

class DnsSecAddKeyController extends \Poweradmin\BaseController {

    public function run(): void
    {
        $zone_id = "-1";
        if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
            $zone_id = htmlspecialchars($_GET['id']);
        }

        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

        if ($user_is_zone_owner == "0") {
            error(ERR_PERM_VIEW_ZONE);
            include_once("inc/footer.inc.php");
            exit();
        }

        if (DnsRecord::zone_id_exists($zone_id) == "0") {
            error(ERR_ZONE_NOT_EXIST);
            include_once("inc/footer.inc.php");
            exit();
        }

        $key_type = "";
        if (isset($_POST['key_type'])) {
            $key_type = $_POST['key_type'];

            if ($key_type != 'ksk' && $key_type != 'zsk') {
                error(ERR_INV_INPUT);
                include_once("inc/footer.inc.php");
                exit;
            }
        }

        $bits = "";
        if (isset($_POST["bits"])) {
            $bits = $_POST["bits"];

            $valid_values = array('2048', '1024', '768', '384', '256');
            if (!in_array($bits, $valid_values)) {
                error(ERR_INV_INPUT);
                include_once("inc/footer.inc.php");
                exit;
            }
        }

        $algorithm = "";
        if (isset($_POST["algorithm"])) {
            $algorithm = $_POST["algorithm"];

            // To check the supported DNSSEC algorithms in your build of PowerDNS, run pdnsutil list-algorithms.
            $valid_algorithm = array('rsasha1', 'rsasha1-nsec3', 'rsasha256', 'rsasha512', 'ecdsa256', 'ecdsa384', 'ed25519', 'ed448');
            if (!in_array($algorithm, $valid_algorithm)) {
                error(ERR_INV_INPUT);
                include_once("inc/footer.inc.php");
                exit;
            }
        }

        $domain_name = DnsRecord::get_domain_name_by_id($zone_id);
        if (isset($_POST["submit"])) {
            if (Dnssec::dnssec_add_zone_key($domain_name, $key_type, $bits, $algorithm)) {
                $this->setMessage('dnssec', 'success', _('Zone key has been added successfully.'));
                $this->redirect('dnssec.php', [id => $zone_id]);
            } else {
                error(ERR_EXEC_PDNSSEC_ADD_ZONE_KEY);
            }
        }

        $this->render('dnssec_add_key.html', [
            'zone_id' => $zone_id,
            'domain_name' => $domain_name,
            'key_type' => $key_type,
            'bits' => $bits,
            'algorithm' => $algorithm,
            'algorithm_names' => Dnssec::dnssec_algorithm_names(),
        ]);
    }
}

$controller = new DnsSecAddKeyController();
$controller->run();
