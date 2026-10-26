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

/**
 * A pending change that can take super admin away from users, described so the
 * repository can count who would still hold it once the change is written.
 */
final readonly class UberuserChange
{
    private function __construct(
        public ?int $droppedTemplateId = null,
        public ?int $deletedGroupId = null,
        public ?int $memberGroupId = null,
        public ?int $memberUserId = null,
        public ?int $retemplatedGroupId = null,
        public ?int $newGroupTemplateId = null
    ) {
    }

    /** No change: counts the super admins there are now. */
    public static function none(): self
    {
        return new self();
    }

    /** The template stops granting user_is_ueberuser. */
    public static function templateDropsUberuser(int $templateId): self
    {
        return new self(droppedTemplateId: $templateId);
    }

    /** The group goes, and its memberships with it. */
    public static function groupDeleted(int $groupId): self
    {
        return new self(deletedGroupId: $groupId);
    }

    /** The user leaves the group. */
    public static function memberRemoved(int $groupId, int $userId): self
    {
        return new self(memberGroupId: $groupId, memberUserId: $userId);
    }

    /** The group moves to another permission template. */
    public static function groupTemplateChanged(int $groupId, int $templateId): self
    {
        return new self(retemplatedGroupId: $groupId, newGroupTemplateId: $templateId);
    }
}
