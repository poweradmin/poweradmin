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

namespace Poweradmin\Domain\Model;

use PDO;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Domain\Service\SessionKeys;

class UserManager
{
    private PDO $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private ?PermissionService $permissionService = null;
    private ?DbUserRepository $userRepository = null;

    public function __construct(PDO $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
    }

    private function userRepository(): DbUserRepository
    {
        return $this->userRepository ??= new DbUserRepository($this->db, $this->config);
    }

    /**
     * Check if the logged-in user has the given permission (admins always pass)
     */
    private function hasPermission(string $permission): bool
    {
        $userId = (new UserContextService())->getLoggedInUserId();
        if ($userId === null) {
            return false;
        }

        return $this->permissions()->hasPermission($userId, $permission);
    }

    private function canDeleteZone(int $zoneId): bool
    {
        $userId = (new UserContextService())->getLoggedInUserId();

        return $userId !== null && $this->permissions()->canDeleteZone($userId, $this->permissions()->userOwnsZone($userId, $zoneId));
    }

    private function canEditZoneMeta(int $zoneId): bool
    {
        $userId = (new UserContextService())->getLoggedInUserId();

        return $userId !== null && $this->permissions()->canEditZoneMeta($userId, $zoneId);
    }

    private function permissions(): PermissionService
    {
        return $this->permissionService ??= new PermissionService($this->userRepository());
    }

    /**
     * Delete User ID
     *
     * Delete a user from the system. Will also delete zones owned by user or
     * re-assign those zones to a new specified owner.
     * $zones is an array of zone 'zid's to delete or re-assign depending on
     * 'target' value [delete,new_owner] and 'newowner' value
     *
     * @param int $uid User ID to delete
     * @param array $zones Array of zones
     *
     * @return boolean true on success, false otherwise
     */
    public function deleteUser(int $uid, array $zones): bool
    {
        if (($uid != $_SESSION[SessionKeys::USERID] && !$this->hasPermission('user_edit_others')) || ($uid == $_SESSION[SessionKeys::USERID] && !$this->hasPermission('user_edit_own'))) {
            $this->messageService->addSystemError(_("You do not have the permission to delete this user."));

            return false;
        }

        if ($this->userRepository()->isLastUberuser($uid)) {
            $this->messageService->addSystemError(_('Cannot delete the last remaining super admin user.'));

            return false;
        }

        // Refuse up front so a later zone cannot fail after earlier ones were already changed.
        foreach ($zones as $zone) {
            if ($zone ['target'] == "delete" && !$this->canDeleteZone((int)$zone ['zid'])) {
                $this->messageService->addSystemError(_("You do not have the permission to delete a zone."));

                return false;
            }
            if ($zone ['target'] == "new_owner" && !$this->canEditZoneMeta((int)$zone ['zid'])) {
                $this->messageService->addSystemError(_('You do not have the permission to edit zone metadata.'));

                return false;
            }
        }

        $domainManager = DnsServiceFactory::createDomainManager($this->db, $this->config);
        foreach ($zones as $zone) {
            $result = match ($zone ['target']) {
                'delete' => $domainManager->deleteDomain((int)$zone ['zid']),
                'new_owner' => $domainManager->addOwnerToZone((int)$zone ['zid'], (int)$zone ['newowner']),
                default => null,
            };
            if ($result !== null && !$result->success) {
                $this->messageService->addSystemError((string)$result->message);

                return false;
            }
        }

        // Row cleanup (auth links, preferences, MFA, memberships, templates) is shared with the API.
        return $this->userRepository()->deleteUser($uid);
    }
}
