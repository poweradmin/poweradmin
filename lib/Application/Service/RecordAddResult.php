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
 * Outcome of adding one record from a form, with the companion PTR or A record
 * the operator asked for alongside it.
 */
final readonly class RecordAddResult
{
    public const COMPANION_PTR = 'ptr';
    public const COMPANION_A = 'a';

    /**
     * @param string|null $companion Which companion was requested, if any
     * @param bool $companionCreated Whether the companion write went through
     * @param bool $companionWarning The companion was created but the creator raised a warning
     * @param string|null $companionMessage The creator's own wording for a warning or failure
     */
    public function __construct(
        public RecordWriteResult $record,
        public ?string $companion = null,
        public bool $companionCreated = false,
        public bool $companionWarning = false,
        public ?string $companionMessage = null
    ) {
    }

    /**
     * Which companion a form asked for: the "reverse" box wants a PTR for the
     * address, "create_domain_record" wants an A record for a PTR in a reverse zone.
     *
     * @param array<string, mixed> $fields The submitted record fields
     */
    public static function companionFrom(array $fields): string
    {
        if (!empty($fields['reverse'])) {
            return self::COMPANION_PTR;
        }

        return !empty($fields['create_domain_record']) ? self::COMPANION_A : '';
    }

    public static function refused(RecordWriteResult $record): self
    {
        return new self($record);
    }

    public function isOk(): bool
    {
        return $this->record->success;
    }
}
