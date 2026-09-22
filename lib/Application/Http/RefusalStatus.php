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

namespace Poweradmin\Application\Http;

use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * The one place a Domain refusal becomes an HTTP status. API controllers call
 * this where they used to read a status number off the result.
 */
final class RefusalStatus
{
    public static function of(Refusal $refusal): int
    {
        return match ($refusal) {
            Refusal::INVALID_INPUT => 400,
            Refusal::FORBIDDEN => 403,
            Refusal::NOT_FOUND => 404,
            Refusal::CONFLICT => 409,
            Refusal::PAYLOAD_TOO_LARGE => 413,
            Refusal::BACKEND_FAILURE => 500,
        };
    }

    /**
     * Status for a ['success' => false, ...] array: a Domain result carries a
     * refusal, an Application-built one may still carry a status number.
     *
     * @param array<string, mixed> $result
     */
    public static function ofResult(array $result, int $default = 400): int
    {
        if (($result['refusal'] ?? null) instanceof Refusal) {
            return self::of($result['refusal']);
        }

        return is_int($result['status'] ?? null) ? $result['status'] : $default;
    }
}
