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

namespace Poweradmin\Application\Presenter;

/**
 * Which users a zone-owner picker offers, and which one it shows again after a
 * refused submit. Whether the caller may pick other users is decided by the
 * permission layer and passed in.
 */
class OwnerOptionsPresenter
{
    /**
     * @param bool $everyone Whether the caller may pick users other than themselves
     * @param list<array<string, mixed>> $users Rows with an 'id' key
     * @return list<array<string, mixed>>
     */
    public static function offered(bool $everyone, array $users, ?int $userId): array
    {
        if ($everyone) {
            return array_values($users);
        }

        return array_values(array_filter($users, static fn(array $user): bool => (int)($user['id'] ?? 0) === $userId));
    }

    /**
     * The owner an add-zone form shows again after a failed submit: the posted
     * id when the picker can offer it, '' for an explicit "no user owner",
     * otherwise the current user.
     *
     * @param list<array<string, mixed>> $assignableOwners
     * @param mixed $ownerInput The raw posted value, if any
     */
    public static function preservedChoice(array $assignableOwners, mixed $ownerInput, ?int $userId): int|string
    {
        if ($ownerInput === '') {
            return '';
        }

        $ownerId = is_scalar($ownerInput) ? filter_var($ownerInput, FILTER_VALIDATE_INT) : false;
        if ($ownerId !== false && in_array($ownerId, array_map('intval', array_column($assignableOwners, 'id')), true)) {
            return $ownerId;
        }

        return (int)$userId;
    }
}
