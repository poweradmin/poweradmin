<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Controller\System;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\Permission;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the edit-supermaster form: updates the IP, nameserver and account of a supermaster.
 */
class EditSupermasterController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_SUPERMASTER_EDIT, _("You do not have the permission to edit a supermaster."));

        $old_master_ip = $this->httpRequest->getQueryParam('master_ip', "");
        $old_ns_name = $this->httpRequest->getQueryParam('ns_name', "");

        $new_master_ip = $this->httpRequest->getPostParam('master_ip', $old_master_ip);
        $new_ns_name = $this->httpRequest->getPostParam('ns_name', $old_ns_name);
        $account = $this->httpRequest->getPostParam('account', "");

        if ($this->isPost()) {
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
                new Assert\Ip(['version' => 'all'])
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

        $postParams = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
            return;
        }

        $supermasterManager = $this->services()->supermasterManager();

        if (!$supermasterManager->supermasterIpNameExists($old_master_ip, $old_ns_name)) {
            $this->setMessage('list_supermasters', 'error', _('The supermaster you are trying to edit does not exist.'));
            $this->redirect('/supermasters');
            return;
        }

        $updated = $supermasterManager->updateSupermaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account);
        if ($updated->success) {
            $this->services()->auditService()->logSupermasterEdit($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name);

            $this->setMessage('list_supermasters', 'success', _('The supermaster has been updated successfully.'));
            $this->redirect('/supermasters');
        } else {
            $this->setMessage('edit_supermaster', 'error', (string)$updated->message);
            $this->showEditSuperMaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account);
        }
    }

    private function showEditSuperMaster($old_master_ip, $old_ns_name, $new_master_ip = null, $new_ns_name = null, $account = null): void
    {
        $supermasterManager = $this->services()->supermasterManager();

        if (!$supermasterManager->supermasterIpNameExists($old_master_ip, $old_ns_name)) {
            $this->setMessage('list_supermasters', 'error', _('The supermaster you are trying to edit does not exist.'));
            $this->redirect('/supermasters');
            return;
        }

        $info = $supermasterManager->getSupermasterInfoFromIp($old_master_ip);

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

        $users = $this->services()->userRepository()->getUsersWithZoneCounts();
        $selectableOwners = $this->services()->zoneOwnershipFormResolver()->selectableOwners($users, (int)$this->getCurrentUserId());
        // The account holder stays listed even when the caller may not see other users.
        foreach ($users as $user) {
            if ($user['username'] === $account && !in_array($user, $selectableOwners, true)) {
                $selectableOwners[] = $user;
            }
        }
        $this->render('edit_supermaster.html', [
            'users' => $users,
            'selectable_owners' => $selectableOwners,
            'master_ip' => $new_master_ip,
            'ns_name' => $new_ns_name,
            'account' => $account,
            'old_master_ip' => $old_master_ip,
            'old_ns_name' => $old_ns_name,
            'perm_view_others' => $this->hasPermission(Permission::PERM_USER_VIEW_OTHERS),
            'session_uid' => $this->getCurrentUserId()
        ]);
    }
}
