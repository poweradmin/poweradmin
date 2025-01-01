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

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;

class AddSupermasterController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('supermaster_add', _("You do not have the permission to add a new supermaster."));

        $master_ip = $_POST["master_ip"] ?? "";
        $ns_name = $_POST["ns_name"] ?? "";
        $account = $_POST["account"] ?? "";

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->addSuperMaster($master_ip, $ns_name, $account);
        } else {
            $this->showAddSuperMaster($master_ip, $ns_name, $account);
        }
    }

    private function addSuperMaster($master_ip, $ns_name, $account): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if ($dnsRecord->add_supermaster($master_ip, $ns_name, $account)) {
            $this->setMessage('list_supermasters', 'success', _('The supermaster has been added successfully.'));
            $this->redirect('index.php', ['page'=> 'list_supermasters']);
        } else {
            $this->showAddSuperMaster($master_ip, $ns_name, $account);
        }
    }

    private function showAddSuperMaster($master_ip, $ns_name, $account): void
    {
        $this->render('add_supermaster.html', [
            'users' => UserManager::show_users($this->db),
            'master_ip' => htmlspecialchars($master_ip),
            'ns_name' => htmlspecialchars($ns_name),
            'account' => htmlspecialchars($account),
            'perm_view_others' => UserManager::verify_permission($this->db, 'user_view_others'),
            'session_uid' => $_SESSION['userid']
        ]);
    }
}
