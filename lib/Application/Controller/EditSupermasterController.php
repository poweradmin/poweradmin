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
 * Script that handles requests to edit supermaster servers
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
use Symfony\Component\Validator\Constraints as Assert;

class EditSupermasterController extends BaseController
{
    public function run(): void
    {
        $this->checkPermission('supermaster_edit', _("You do not have the permission to edit a supermaster."));

        $old_master_ip = $_GET["master_ip"] ?? "";
        $old_ns_name = $_GET["ns_name"] ?? "";

        $new_master_ip = $_POST["master_ip"] ?? $old_master_ip;
        $new_ns_name = $_POST["ns_name"] ?? $old_ns_name;
        $account = $_POST["account"] ?? "";

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->updateSuperMaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account);
        } else {
            $this->showEditSuperMaster($old_master_ip, $old_ns_name);
        }
    }

    private function updateSuperMaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account): void
    {
        $constraints = [
            'master_ip' => [
                new Assert\NotBlank(),
                new Assert\Ip()
            ],
            'ns_name' => [
                new Assert\NotBlank(),
                new Assert\Hostname()
            ],
            'account' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        if (!$dnsRecord->supermasterIpNameExists($old_master_ip, $old_ns_name)) {
            $this->setMessage('list_supermasters', 'error', _('The supermaster you are trying to edit does not exist.'));
            $this->redirect('/supermasters');
            return;
        }

        if ($dnsRecord->updateSupermaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account)) {
            $this->setMessage('list_supermasters', 'success', _('The supermaster has been updated successfully.'));
            $this->redirect('/supermasters');
        } else {
            $this->showEditSuperMaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account);
        }
    }

    private function showEditSuperMaster($old_master_ip, $old_ns_name, $new_master_ip = null, $new_ns_name = null, $account = null): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        if (!$dnsRecord->supermasterIpNameExists($old_master_ip, $old_ns_name)) {
            $this->setMessage('list_supermasters', 'error', _('The supermaster you are trying to edit does not exist.'));
            $this->redirect('/supermasters');
            return;
        }

        $info = $dnsRecord->getSupermasterInfoFromIp($old_master_ip);

        // If POST didn't provide values, use the existing ones
        if ($new_master_ip === null) {
            $new_master_ip = $old_master_ip;
        }

        if ($new_ns_name === null) {
            $new_ns_name = $info['ns_name'];
        }

        if ($account === null) {
            $account = $info['account'];
        }

        $this->render('edit_supermaster.html', [
            'users' => UserManager::showUsers($this->db),
            'master_ip' => htmlspecialchars($new_master_ip),
            'ns_name' => htmlspecialchars($new_ns_name),
            'account' => htmlspecialchars($account),
            'old_master_ip' => htmlspecialchars($old_master_ip),
            'old_ns_name' => htmlspecialchars($old_ns_name),
            'perm_view_others' => UserManager::verifyPermission($this->db, 'user_view_others'),
            'session_uid' => $_SESSION['userid']
        ]);
    }
}
