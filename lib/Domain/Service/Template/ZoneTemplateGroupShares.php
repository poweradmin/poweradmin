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

use Exception;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;

/**
 * Which user groups a zone template is shared with. The owner shares with groups
 * they belong to, a ueberuser with any group; members of a group see, apply and
 * (with zone_templ_edit) edit the template.
 */
class ZoneTemplateGroupShares
{
    private ZoneTemplateRepositoryInterface $repository;
    private ZoneTemplateAccessPolicy $access;
    private UserGroupLookupInterface $groups;

    public function __construct(
        ZoneTemplateRepositoryInterface $repository,
        ZoneTemplateAccessPolicy $access,
        UserGroupLookupInterface $groups
    ) {
        $this->repository = $repository;
        $this->access = $access;
        $this->groups = $groups;
    }

    /**
     * Groups the user may add to or remove from a template's share list.
     *
     * @return UserGroup[]
     */
    public function shareableGroups(int $userId): array
    {
        if ($this->access->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            return $this->groups->findAll();
        }

        return $this->groups->findByUserId($userId);
    }

    /**
     * Groups the template is shared with, as the user may see them.
     *
     * @return list<array{id: int, name: string}>
     */
    public function sharedGroups(int $zone_templ_id, int $userId): array
    {
        $viewer = $this->access->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER) ? null : $userId;

        return $this->repository->listGroupsForTemplates([$zone_templ_id], $viewer)[$zone_templ_id] ?? [];
    }

    /**
     * Refusal for a share list update(), or null when it would be accepted. Lets a
     * caller check the list before saving the rest of the form.
     *
     * @param array<mixed> $submittedGroupIds
     */
    public function refusal(int $zone_templ_id, array $submittedGroupIds, int $userId): ?ZoneTemplateWriteResult
    {
        $plan = $this->plan($zone_templ_id, $submittedGroupIds, $userId);

        return $plan instanceof ZoneTemplateWriteResult ? $plan : null;
    }

    /**
     * The groups a template being created is shared with, or the refusal. The creator
     * becomes the owner, so the owner's rules apply.
     *
     * @param array<mixed> $submittedGroupIds
     * @return ZoneTemplateWriteResult|list<int>
     */
    public function groupsForNewTemplate(array $submittedGroupIds, int $userId): ZoneTemplateWriteResult|array
    {
        $submitted = $this->parseGroupIds($submittedGroupIds);
        if ($submitted === null) {
            return ZoneTemplateWriteResult::failure(_('Invalid group selection.'));
        }
        if ($submitted === []) {
            return [];
        }
        if (!$this->access->canCurrentUserManageTemplate($userId)) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to share this zone template.'));
        }
        if (array_diff_key($submitted, $this->shareableIds($userId)) !== []) {
            return ZoneTemplateWriteResult::forbidden(_('You can only share a zone template with groups you belong to.'));
        }
        ksort($submitted);

        return array_keys($submitted);
    }

    /**
     * Set the template's share list to the submitted groups. Stored groups the user
     * may not share with stay as they are; submitting one that is not stored is refused.
     *
     * @param array<mixed> $submittedGroupIds
     */
    public function update(int $zone_templ_id, array $submittedGroupIds, int $userId): ZoneTemplateWriteResult
    {
        $plan = $this->plan($zone_templ_id, $submittedGroupIds, $userId);
        if ($plan instanceof ZoneTemplateWriteResult) {
            return $plan;
        }
        if ($plan === null) {
            return ZoneTemplateWriteResult::ok();
        }

        try {
            $this->repository->replaceTemplateGroups($zone_templ_id, $plan);
            return ZoneTemplateWriteResult::ok();
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error sharing zone template: ') . $e->getMessage());
        }
    }

    /**
     * @param array<mixed> $submittedGroupIds
     * @return ZoneTemplateWriteResult|list<int>|null A refusal, the new group ids, or null when unchanged
     */
    private function plan(int $zone_templ_id, array $submittedGroupIds, int $userId): ZoneTemplateWriteResult|array|null
    {
        if (!$this->access->canCurrentUserManageTemplateById($zone_templ_id)) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to share this zone template.'));
        }

        $submitted = $this->parseGroupIds($submittedGroupIds);
        if ($submitted === null) {
            return ZoneTemplateWriteResult::failure(_('Invalid group selection.'));
        }

        $shareable = $this->shareableIds($userId);
        $stored = array_column($this->repository->listGroupsForTemplates([$zone_templ_id])[$zone_templ_id] ?? [], 'id', 'id');

        if (array_diff_key($submitted, $shareable, $stored) !== []) {
            return ZoneTemplateWriteResult::forbidden(_('You can only share a zone template with groups you belong to.'));
        }

        $next = array_diff_key($stored, $shareable) + array_intersect_key($submitted, $shareable);
        ksort($next);
        ksort($stored);

        return array_keys($next) === array_keys($stored) ? null : array_keys($next);
    }

    /**
     * @param array<mixed> $submittedGroupIds
     * @return array<int, int>|null The ids keyed by themselves, or null when one is not a positive integer
     */
    private function parseGroupIds(array $submittedGroupIds): ?array
    {
        $ids = [];
        foreach ($submittedGroupIds as $groupId) {
            if (!is_scalar($groupId) || !ctype_digit((string)$groupId) || (int)$groupId <= 0) {
                return null;
            }
            $ids[(int)$groupId] = (int)$groupId;
        }

        return $ids;
    }

    /** @return array<int, int> */
    private function shareableIds(int $userId): array
    {
        $ids = [];
        foreach ($this->shareableGroups($userId) as $group) {
            $ids[(int)$group->getId()] = (int)$group->getId();
        }

        return $ids;
    }
}
