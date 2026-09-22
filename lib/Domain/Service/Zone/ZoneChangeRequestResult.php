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
 * Outcome of filing, approving, rejecting or cancelling a change request.
 * Messages are plain English; web callers map the code to a translated line.
 */
final readonly class ZoneChangeRequestResult
{
    public const CODE_OK = 'ok';
    public const CODE_NOT_FOUND = 'not_found';
    public const CODE_NOT_PENDING = 'not_pending';
    public const CODE_NOT_REQUESTER = 'not_requester';
    public const CODE_READ_ONLY_ZONE = 'read_only_zone';
    public const CODE_NO_CHANGES = 'no_changes';
    public const CODE_TRUNCATED = 'truncated';
    public const CODE_VALIDATION = 'validation';
    public const CODE_RECORD_NOT_FOUND = 'record_not_found';
    public const CODE_PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const CODE_APPLY_FAILED = 'apply_failed';

    /**
     * @param list<string> $errors Per-row refusals when filing was refused by validation
     */
    private function __construct(
        public bool $success,
        public string $code,
        public string $message,
        public ?Refusal $refusal,
        public ?int $requestId,
        public array $errors
    ) {
    }

    public static function ok(?int $requestId, string $message): self
    {
        return new self(true, self::CODE_OK, $message, null, $requestId, []);
    }

    /** @param list<string> $errors */
    public static function failure(string $code, string $message, Refusal $refusal = Refusal::INVALID_INPUT, array $errors = [], ?int $requestId = null): self
    {
        return new self(false, $code, $message, $refusal, $requestId, $errors);
    }
}
