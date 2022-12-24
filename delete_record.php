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
 * Script that handles record deletions from zones
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
use Poweradmin\Logger;

require_once 'inc/toolkit.inc.php';
require_once 'inc/messages.inc.php';

class DeleteRecordController extends BaseController {

    public function run(): void
    {
        if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
            error(_('Invalid or unexpected input given.'));
            include_once('inc/footer.inc.php');
            exit;
        }

        $record_id = htmlspecialchars($_GET['id']);
        $zid = DnsRecord::get_zone_id_from_record_id($record_id);
        if ($zid == NULL) {
            $this->showError(_('There is no zone with this ID.'));
        }

        if (isset($_GET['confirm'])) {
            $record_info = DnsRecord::get_record_from_id($record_id);
            if (DnsRecord::delete_record($record_id)) {
                if (isset($record_info['prio'])) {
                    Logger::log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s priority:%s',
                        $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                        $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl'], $record_info['prio']), $zid);
                } else {
                    Logger::log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s',
                        $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                        $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl']), $zid);
                }

                DnsRecord::delete_record_zone_templ($record_id);
                DnsRecord::update_soa_serial($zid);

                $this->config('pdnssec_use') && Dnssec::dnssec_rectify_zone($zid);

                $this->setMessage('edit', 'success', _('The record has been deleted successfully.'));
                $this->redirect('edit.php', ['id' => $zid]);
            }
        }

        $perm_edit = Permission::getEditPermission();

        $zone_info = DnsRecord::get_zone_info_from_id($zid);
        $zone_id = DnsRecord::recid_to_domid($record_id);
        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid' , $zone_id );
        if ($zone_info['type'] == "SLAVE" || $perm_edit == "none" || ($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to edit this record."));
        }

        $this->showQuestion($record_id, $zid, $zone_id);
    }

    public function showQuestion(string $record_id, $zid, int $zone_id): void
    {
        $this->render('delete_record.html', [
            'record_id' => $record_id,
            'zid' => $zid,
            'zone_name' => DnsRecord::get_domain_name_by_id($zone_id),
            'record_info' => DnsRecord::get_record_from_id($record_id),
        ]);
    }
}

$controller = new DeleteRecordController();
$controller->run();
