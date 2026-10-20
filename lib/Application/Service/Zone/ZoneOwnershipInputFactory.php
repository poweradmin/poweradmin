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

namespace Poweradmin\Application\Service\Zone;

use Poweradmin\Domain\Service\Zone\ZoneOwnershipInput;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Reads the owner fields of a decoded zone creation body into the typed input
 * ZoneCreateOwnershipResolver takes; a value of the wrong shape is refused
 * with the same wording and status the resolver used to give.
 */
final class ZoneOwnershipInputFactory
{
    /**
     * @param array<string, mixed> $body Decoded JSON body of POST /zones
     */
    public static function fromJsonBody(array $body): ZoneOwnershipInput|ZoneOwnershipResolution
    {
        $groupIds = null;
        if (array_key_exists('group_ids', $body)) {
            if (!is_array($body['group_ids'])) {
                return self::invalid('group_ids must be an array of integers');
            }
            $groupIds = [];
            foreach ($body['group_ids'] as $candidate) {
                if (!self::isId($candidate)) {
                    return self::invalid('group_ids must be an array of integers');
                }
                $groupIds[] = (int)$candidate;
            }
        }

        if (!array_key_exists('owner_user_id', $body)) {
            return ZoneOwnershipInput::ownerOmitted($groupIds);
        }

        $rawOwner = $body['owner_user_id'];
        if ($rawOwner === null) {
            return ZoneOwnershipInput::owner(null, $groupIds);
        }
        if (!self::isId($rawOwner)) {
            return self::invalid('owner_user_id must be a numeric ID');
        }

        return ZoneOwnershipInput::owner((int)$rawOwner, $groupIds);
    }

    private static function isId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    private static function invalid(string $message): ZoneOwnershipResolution
    {
        return ZoneOwnershipResolution::error($message, Refusal::INVALID_INPUT, ZoneOwnershipResolution::INVALID_INPUT);
    }
}
