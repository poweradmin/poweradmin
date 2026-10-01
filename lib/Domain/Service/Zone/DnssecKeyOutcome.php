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

use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * What a DnssecKeyService request came to. Callers word these for their own
 * audience; the API and the web key pages agree on the steps.
 */
enum DnssecKeyOutcome: string
{
    case LISTED = 'listed';
    case FOUND = 'found';
    case ADDED = 'added';
    case UPDATED = 'updated';
    case UNCHANGED = 'unchanged';
    case REMOVED = 'removed';
    case INVALID_TYPE = 'invalid_type';
    case INVALID_ALGORITHM = 'invalid_algorithm';
    case INVALID_BITS = 'invalid_bits';
    case INVALID_PRIVATE_KEY = 'invalid_private_key';
    case KEY_ALGORITHM_MISMATCH = 'key_algorithm_mismatch';
    case KEY_REJECTED = 'key_rejected';
    case UNREACHABLE = 'unreachable';
    case SERVER_DISABLED = 'server_disabled';
    case PRESIGNED = 'presigned';
    case NOT_FOUND = 'not_found';
    case FAILED = 'failed';

    /**
     * Why the request was refused, or null when it went through.
     */
    public function refusal(): ?Refusal
    {
        return match ($this) {
            self::LISTED, self::FOUND, self::ADDED, self::UPDATED, self::UNCHANGED, self::REMOVED => null,
            self::INVALID_TYPE, self::INVALID_ALGORITHM, self::INVALID_BITS, self::SERVER_DISABLED,
            self::INVALID_PRIVATE_KEY, self::KEY_ALGORITHM_MISMATCH, self::KEY_REJECTED => Refusal::INVALID_INPUT,
            self::PRESIGNED => Refusal::CONFLICT,
            self::NOT_FOUND => Refusal::NOT_FOUND,
            self::FAILED => Refusal::BACKEND_FAILURE,
            self::UNREACHABLE => Refusal::BACKEND_UNREACHABLE,
        };
    }
}
