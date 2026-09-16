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

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\SessionKeys;

/**
 * Handles the add-supermaster form: validates the IP and nameserver, then stores the supermaster entry.
 */
class AddSupermasterController extends BaseController
{

    public function __construct(array $request)
    {
        parent::__construct($request);
    }

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_SUPERMASTER_ADD, _("You do not have the permission to add a new supermaster."));

        $master_ip = $this->httpRequest->getPostParam('master_ip', "");
        $ns_name = $this->httpRequest->getPostParam('ns_name', "");
        $account = $this->httpRequest->getPostParam('account', "");

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->addSuperMaster($master_ip, $ns_name, $account);
        } else {
            $this->showAddSuperMaster($master_ip, $ns_name, $account);
        }
    }

    private function addSuperMaster($master_ip, $ns_name, $account): void
    {
        $added = $this->createSupermasterManager()->addSupermaster($master_ip, $ns_name, $account);
        if ($added->success) {
            $this->createAuditService()->logSupermasterAdd($master_ip, $ns_name);

            $this->setMessage('list_supermasters', 'success', _('The supermaster has been added successfully.'));
            $this->redirect('/supermasters');
        } else {
            $this->setMessage('add_supermaster', 'error', (string)$added->message);
            $this->showAddSuperMaster($master_ip, $ns_name, $account);
        }
    }

    private function showAddSuperMaster($master_ip, $ns_name, $account): void
    {
        $users = $this->createUserRepository()->getUsersWithZoneCounts();
        $this->render('add_supermaster.html', [
            'users' => $users,
            'selectable_owners' => $this->selectableOwners($users),
            'master_ip' => htmlspecialchars($master_ip),
            'ns_name' => htmlspecialchars($ns_name),
            'account' => htmlspecialchars($account),
            'perm_view_others' => $this->hasPermission(Permission::PERM_USER_VIEW_OTHERS),
            'session_uid' => $_SESSION[SessionKeys::USERID]
        ]);
    }
}
