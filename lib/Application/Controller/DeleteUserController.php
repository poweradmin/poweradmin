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
 * Script that handles user deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserEntity;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Domain\Service\Validator;

class DeleteUserController extends BaseController
{

    public function run(): void
    {
        $perm_edit_others = UserManager::verifyPermission($this->db, 'user_edit_others');
        $perm_is_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');

        $uid = $this->getSafeRequestValue('id');
        if (!$uid || !Validator::isNumber($uid)) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        // Check basic permissions first
        if (($uid != $_SESSION['userid'] && !$perm_edit_others) || ($uid == $_SESSION['userid'] && !$perm_is_godlike)) {
            $this->showError(_("You do not have the permission to delete this user."));
        }

        // Prevent non-superusers from deleting superuser accounts (privilege escalation protection)
        $targetIsSuperuser = UserManager::isUserSuperuser($this->db, $uid);

        if ($targetIsSuperuser && !$perm_is_godlike) {
            $this->showError(_('You do not have permission to delete a superuser account.'));
        }

        // All permission checks passed, now handle POST request
        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->deleteUser($uid);
        }

        $this->showQuestion($uid);
    }

    public function deleteUser(string $uid): void
    {
        if (!UserManager::isValidUser($this->db, $uid)) {
            $this->showError(_('User does not exist.'));
        }

        $zones = array();
        if (isset($_POST['zone'])) {
            $zones = $_POST['zone'];
        }

        $legacyUsers = new UserManager($this->db, $this->getConfig());
        if ($legacyUsers->deleteUser($uid, $zones)) {
            $this->setMessage('users', 'success', _('The user has been deleted successfully.'));
            $this->redirect('/users');
        }
    }

    public function showQuestion(string $uid): void
    {
        $name = UserManager::getFullnameFromUserId($this->db, $uid);
        if (!$name) {
            $name = UserEntity::getUserNameById($this->db, $uid);
        }
        $domainRepository = new DomainRepository($this->db, $this->getConfig());
        $zones = $domainRepository->getZones("own", (int)$uid);

        $users = [];
        if (count($zones) > 0) {
            $users = UserManager::showUsers($this->db);
        }

        $this->render('delete_user.html', [
            'name' => $name,
            'uid' => $uid,
            'zones' => $zones,
            'zones_count' => count($zones),
            'users' => $users,
        ]);
    }
}
