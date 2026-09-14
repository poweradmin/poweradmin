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

/**
 * Outcome of a record write. Replaces the bool return plus MessageService side
 * channel: callers read the reason, the HTTP status and the offending form field
 * from here instead of the session.
 */
final readonly class RecordWriteResult
{
    public const FIELD_NAME = 'name';
    public const FIELD_CONTENT = 'content';
    public const FIELD_TTL = 'ttl';
    public const FIELD_PRIO = 'prio';
    public const FIELD_DUPLICATE = 'name-content-duplicate';

    private function __construct(
        public bool $success,
        public ?string $message,
        public int $status,
        public ?string $field,
        public int|string|null $recordId
    ) {
    }

    /** @param int|string|null $recordId The new id on create; edits and deletes carry none */
    public static function ok(int|string|null $recordId = null): self
    {
        return new self(true, null, 200, null, $recordId);
    }

    /**
     * @param string|null $field The form field the message is about; guessed from the message when omitted
     */
    public static function failure(string $message, int $status = 400, ?string $field = null): self
    {
        return new self(false, $message, $status, $field ?? self::fieldForMessage($message), null);
    }

    public static function forbidden(string $message): self
    {
        return new self(false, $message, 403, null, null);
    }

    public static function notFound(string $message): self
    {
        return new self(false, $message, 404, null, null);
    }

    public static function backendFailure(string $message): self
    {
        return new self(false, $message, 500, null, null);
    }

    /**
     * Validators report a message, not a field; this keeps the one heuristic the
     * forms used to carry each, until ValidationResult names the field itself.
     */
    public static function fieldForMessage(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'already exists')) {
            return self::FIELD_DUPLICATE;
        }
        if (preg_match('/\bname\b/', $lower) && str_contains($lower, 'invalid')) {
            return self::FIELD_NAME;
        }
        foreach (['content', 'value', 'address', 'hostname'] as $hint) {
            if (str_contains($lower, $hint)) {
                return self::FIELD_CONTENT;
            }
        }
        if (str_contains($lower, 'ttl')) {
            return self::FIELD_TTL;
        }
        if (str_contains($lower, 'prio')) {
            return self::FIELD_PRIO;
        }

        return self::FIELD_CONTENT;
    }
}
