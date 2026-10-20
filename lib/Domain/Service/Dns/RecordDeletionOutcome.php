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
 * Outcome of deleting a record together with its reverse counterpart.
 *
 * The candidate flags say whether the record was of a kind that can have a
 * counterpart (A/AAAA for a PTR, PTR for an A/AAAA) with reverse handling
 * switched on; the deleted flags say whether one was found and removed.
 */
final readonly class RecordDeletionOutcome
{
    private function __construct(
        public bool $recordDeleted,
        public bool $ptrCandidate,
        public bool $ptrDeleted,
        public bool $forwardCandidate,
        public bool $forwardDeleted,
        public ?string $message,
        public bool $notFound
    ) {
    }

    public static function deleted(bool $ptrCandidate, bool $ptrDeleted, bool $forwardCandidate, bool $forwardDeleted): self
    {
        return new self(true, $ptrCandidate, $ptrDeleted, $forwardCandidate, $forwardDeleted, null, false);
    }

    public static function notFound(string $message): self
    {
        return new self(false, false, false, false, false, $message, true);
    }

    public static function failed(string $message): self
    {
        return new self(false, false, false, false, false, $message, false);
    }
}
