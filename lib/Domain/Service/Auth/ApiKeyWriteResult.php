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

namespace Poweradmin\Domain\Service\Auth;

use Poweradmin\Domain\Model\ApiKey;

/**
 * Outcome of an API key write: the saved key on success, otherwise the reason,
 * its code and the HTTP status.
 */
final readonly class ApiKeyWriteResult
{
    public const ERR_API_DISABLED = 'api_disabled';
    public const ERR_FORBIDDEN = 'forbidden';
    public const ERR_LIMIT = 'limit';
    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_WRITE = 'write';

    private function __construct(
        public bool $success,
        public ?ApiKey $key,
        public ?string $message,
        public int $status,
        public ?string $code
    ) {
    }

    public static function ok(?ApiKey $key = null): self
    {
        return new self(true, $key, null, 200, null);
    }

    public static function refused(string $code, string $message, int $status): self
    {
        return new self(false, null, $message, $status, $code);
    }
}
