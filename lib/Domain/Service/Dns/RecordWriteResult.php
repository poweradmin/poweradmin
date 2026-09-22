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

namespace Poweradmin\Domain\Service\Dns;

use Poweradmin\Domain\Service\Validation\RecordField;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Outcome of a record write. Replaces the bool return plus MessageService side
 * channel: callers read the reason, the refusal and the offending record
 * part from here instead of the session.
 */
final readonly class RecordWriteResult
{
    private function __construct(
        public bool $success,
        public ?string $message,
        public ?Refusal $refusal,
        public ?RecordField $field,
        public int|string|null $recordId
    ) {
    }

    /** @param int|string|null $recordId The new id on create; edits and deletes carry none */
    public static function ok(int|string|null $recordId = null): self
    {
        return new self(true, null, null, null, $recordId);
    }

    /**
     * @param RecordField|null $field The record part the message is about, when the validator named one
     */
    public static function failure(string $message, Refusal $refusal = Refusal::INVALID_INPUT, ?RecordField $field = null): self
    {
        return new self(false, $message, $refusal, $field, null);
    }

    public static function forbidden(string $message): self
    {
        return new self(false, $message, Refusal::FORBIDDEN, null, null);
    }

    public static function notFound(string $message): self
    {
        return new self(false, $message, Refusal::NOT_FOUND, null, null);
    }

    public static function backendFailure(string $message): self
    {
        return new self(false, $message, Refusal::BACKEND_FAILURE, null, null);
    }
}
