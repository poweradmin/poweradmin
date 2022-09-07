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

use Poweradmin\AppFactory;
use Poweradmin\DnsRecord;
use Poweradmin\Dnssec;
use Poweradmin\Validation;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

include_once 'inc/header.inc.php';

$app = AppFactory::create();

$zone_id = "-1";
if (isset($_GET['id']) && Validation::is_number($_GET['id'])) {
    $zone_id = htmlspecialchars($_GET['id']);
}

$key_id = "-1";
if (isset($_GET['key_id']) && Validation::is_number($_GET['key_id'])) {
    $key_id = (int) $_GET['key_id'];
}

$confirm = "-1";
if (isset($_GET['confirm']) && Validation::is_number($_GET['confirm'])) {
    $confirm = $_GET['confirm'];
}

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid' , $zone_id );

if ($zone_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

$domain_name = DnsRecord::get_domain_name_by_id($zone_id);

if ($key_id == "-1") {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;
}

if (!Dnssec::dnssec_zone_key_exists($domain_name, $key_id)) {
    error(ERR_INV_INPUT);
    include_once("inc/footer.inc.php");
    exit;    
}

echo "     <h5 class=\"mb-3\">" . _('Delete zone key') . "</h5>\n";

if ($confirm == '1') {
    if (Dnssec::dnssec_remove_zone_key($domain_name, $key_id)) {
        success(SUC_EXEC_PDNSSEC_REMOVE_ZONE_KEY);
    }
} else {
    if ($user_is_zone_owner == "1") {
        $key_info = Dnssec::dnssec_get_zone_key($domain_name, $key_id);
        echo "      " . _('Domain') . ": " . $domain_name . "<br>\n";
        echo "      " . _('Id') . ": " . $key_info[0] . "<br>\n";
        echo "      " . _('Type') . ": " . $key_info[1] . "<br>\n";
        echo "      " . _('Tag') . ": " . $key_info[2] . "<br>\n";
        echo "      " . _('Algorithm') . ": " . Dnssec::dnssec_algorithm_to_name($key_info[3]) . "<br>\n";
        echo "      " . _('Bits') . ": " . $key_info[4] . "<br>\n";
        echo "      " . _('Active') . ": " . ($key_info[5] ? _('Yes') : _('No')) . "\n";
        echo "     <p class=\"pt-3\">" . _('Are you sure?') . "</p>\n";
        echo "     <input class=\"btn btn-primary btn-sm\" type=\"button\" onClick=\"location.href='dnssec_delete_key.php?id=" . $zone_id . "&amp;key_id=$key_id&amp;confirm=1'\" value=\"" . _('Yes') . "\">\n";
        echo "     <input class=\"btn btn-secondary btn-sm\" type=\"button\" onClick=\"location.href='dnssec.php?id=" . $zone_id . "'\" value=\"" . _('No') . "\">\n";
    } else {
        error(ERR_PDNSSEC_DEL_ZONE_KEY);
    }
}
echo "<p class=\"pt-3\"><a href='dnssec.php?id=" . $zone_id . "'>Back to DNSSEC " . $domain_name . "</a></p>";
include_once("inc/footer.inc.php");
