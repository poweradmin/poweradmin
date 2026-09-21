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

namespace Poweradmin\Domain\Service\Template;

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * What the acting user may do with zone templates: which template a zone may
 * be created from, who owns a template, which record types may be stored in
 * one, and how far the linked-zone listings reach.
 */
class ZoneTemplateAccessPolicy
{
    private ZoneTemplateRepositoryInterface $repository;
    private PermissionService $permissionService;
    private ActorInterface $actor;

    public function __construct(
        ZoneTemplateRepositoryInterface $repository,
        PermissionService $permissionService,
        ActorInterface $actor
    ) {
        $this->repository = $repository;
        $this->permissionService = $permissionService;
        $this->actor = $actor;
    }

    /**
     * Check if the logged-in user has the given permission (admins always pass)
     */
    public function currentUserHasPermission(string $permission): bool
    {
        $userId = $this->actor->userId();
        if ($userId === null) {
            return false;
        }
        return $this->permissionService->hasPermission($userId, $permission);
    }

    /**
     * The logged-in user's edit level: "all", "own", "own_as_client" or "none".
     */
    public function currentUserEditPermissionLevel(): string
    {
        $userId = $this->actor->userId();
        if ($userId === null) {
            return 'none';
        }
        return $this->permissionService->getEditPermissionLevel($userId);
    }

    /**
     * Confirm the current user may store this record type in a zone template.
     */
    public function canStoreTemplateRecordType(string $type): bool
    {
        return !Permission::isTemplateRecordTypeRestricted($type, $this->currentUserEditPermissionLevel());
    }

    /**
     * Resolve the owner column for a template. A global template (owner 0) is
     * reserved for ueberusers; anyone else owns the template personally.
     *
     * @return int 0 for a permitted global template, otherwise the user id
     */
    public function resolveTemplateOwner(bool $requestedGlobal, int $userid): int
    {
        if ($requestedGlobal && $this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            return 0;
        }

        return $userid;
    }

    /**
     * Owner the zone listings are narrowed to: null when the user may edit every
     * zone, otherwise the user themselves.
     */
    public function linkedZoneOwnerFilter(int $userid): ?int
    {
        return $this->currentUserEditPermissionLevel() !== 'all' ? $userid : null;
    }

    /**
     * Whether a posted template id may be applied to a zone.
     *
     * Follows the listing scope: no template, a global template (owner 0), the
     * user's own template, or any template for an administrator.
     *
     * @param mixed $zone_templ_id Posted template id ("none", "", 0 or an id)
     */
    public function canUseTemplate(mixed $zone_templ_id, int $userid, bool $isAdmin): bool
    {
        if ($zone_templ_id === null || $zone_templ_id === '' || $zone_templ_id === 'none') {
            return true;
        }
        if (!is_numeric($zone_templ_id)) {
            return false;
        }
        $zone_templ_id = (int)$zone_templ_id;
        if ($zone_templ_id === 0) {
            return true;
        }
        if ($isAdmin) {
            return true;
        }

        $owner = $this->repository->getOwner($zone_templ_id);
        if ($owner === null) {
            return false;
        }

        return $owner === 0 || $owner === $userid;
    }

    /**
     * canUseTemplate() for the logged-in user.
     */
    public function canCurrentUserUseTemplate(mixed $zone_templ_id): bool
    {
        $userId = (int)($this->actor->userId() ?? 0);

        return $this->canUseTemplate($zone_templ_id, $userId, $this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER));
    }

    /**
     * Check if the user is the owner of the zone template
     */
    public function isUserOwnerOfTemplate(int $zone_templ_id, int $userid): bool
    {
        return $this->repository->isOwner($zone_templ_id, $userid);
    }

    /**
     * Whether the logged-in user may edit or delete a template with this owner
     * column: a ueberuser, or the owner holding zone_templ_edit. Global
     * templates (owner 0) are only editable by ueberusers.
     */
    public function canCurrentUserEditTemplate(int $owner): bool
    {
        if ($this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            return true;
        }

        $userId = $this->actor->userId();

        return $userId !== null && $owner === $userId
            && $this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT);
    }
}
