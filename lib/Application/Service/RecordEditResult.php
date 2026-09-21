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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Service\Dns\RecordWriteResult;

/**
 * Outcome of editing one record: the write itself, the row as it is stored
 * afterwards, and how the PTR sync went when one was asked for.
 */
final readonly class RecordEditResult
{
    /**
     * @param int|string|null $recordId The id the record has after the write; the API backend
     *                                  re-keys a record whose name, type, content or prio changed
     * @param array<string, mixed> $record The stored row after the write, or the submitted values
     *                                     when the backend no longer resolves the old id
     * @param bool|null $ptrUpdated Null when no sync was attempted, otherwise whether it succeeded
     * @param string|null $ptrMessage The reverse creator's own wording for the sync outcome
     */
    public function __construct(
        public RecordWriteResult $write,
        public int|string|null $recordId = null,
        public array $record = [],
        public ?bool $ptrUpdated = null,
        public ?string $ptrMessage = null
    ) {
    }

    public static function refused(RecordWriteResult $write): self
    {
        return new self($write);
    }

    public function isOk(): bool
    {
        return $this->write->success;
    }

    public function ptrFailed(): bool
    {
        return $this->ptrUpdated === false;
    }
}
