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

namespace Poweradmin\Domain\Service\Zone;

/**
 * Outcome of {@see ZoneCreateOwnershipResolver}: either a resolved owner/group
 * assignment for the new zone, or an error with HTTP status. The error carries
 * a code so the web forms can word it themselves; the message is the API text.
 */
final readonly class ZoneOwnershipResolution
{
    public const NO_OWNER = 'no_owner';
    public const OTHER_OWNER_FORBIDDEN = 'other_owner_forbidden';
    public const UNKNOWN_OWNER = 'unknown_owner';
    public const UNKNOWN_GROUPS = 'unknown_groups';
    public const GROUPS_NOT_MEMBER = 'groups_not_member';
    public const INVALID_INPUT = 'invalid_input';
    public const USER_OWNER_DISABLED = 'user_owner_disabled';
    public const GROUP_OWNER_DISABLED = 'group_owner_disabled';
    public const NO_GROUPS_EXIST = 'no_groups_exist';
    public const NOT_IN_ANY_GROUP = 'not_in_any_group';

    /**
     * @param int|null   $owner    Resolved user owner (null when no user owner).
     * @param list<int>  $groupIds Resolved unique group ids (empty when none).
     * @param string|null $error   Error message; null on success.
     * @param int        $status   HTTP status code to return on error.
     * @param string|null $code    One of the class constants; null on success.
     * @param list<int>  $ids      The user or group ids the error is about, if any.
     */
    private function __construct(
        public ?int $owner,
        public array $groupIds,
        public ?string $error,
        public int $status,
        public ?string $code,
        public array $ids,
    ) {
    }

    /**
     * @param list<int> $groupIds
     */
    public static function success(?int $owner, array $groupIds): self
    {
        return new self($owner, $groupIds, null, 200, null, []);
    }

    /**
     * @param list<int> $ids
     */
    public static function error(string $message, int $status, string $code, array $ids = []): self
    {
        return new self(null, [], $message, $status, $code, $ids);
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }
}
