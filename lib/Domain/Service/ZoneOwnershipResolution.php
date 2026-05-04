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

namespace Poweradmin\Domain\Service;

/**
 * Outcome of {@see ZoneCreateOwnershipResolver::resolve()}: either a resolved
 * owner/group assignment for the new zone, or an error with HTTP status.
 */
final class ZoneOwnershipResolution
{
    /**
     * @param int|null   $owner    Resolved user owner (null when no user owner).
     * @param list<int>  $groupIds Resolved unique group ids (empty when none).
     * @param string|null $error   Error message; null on success.
     * @param int        $status   HTTP status code to return on error.
     */
    private function __construct(
        public readonly ?int $owner,
        public readonly array $groupIds,
        public readonly ?string $error,
        public readonly int $status,
    ) {
    }

    /**
     * @param list<int> $groupIds
     */
    public static function success(?int $owner, array $groupIds): self
    {
        return new self($owner, $groupIds, null, 200);
    }

    public static function error(string $message, int $status): self
    {
        return new self(null, [], $message, $status);
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }
}
