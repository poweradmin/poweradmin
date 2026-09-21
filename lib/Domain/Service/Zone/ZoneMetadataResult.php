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

/**
 * Outcome of a ZoneMetadataService write, with the metadata kind and options the caller needs to word a refusal.
 */
final readonly class ZoneMetadataResult
{
    /**
     * @param string $kind The kind the refusal is about; empty on success
     * @param list<string>|null $options The vocabulary, when a value fell outside it
     * @param string|null $companion The kind the refused one needs next to it
     */
    public function __construct(
        public ZoneMetadataOutcome $outcome,
        public string $kind = '',
        public ?array $options = null,
        public ?string $companion = null
    ) {
    }

    public static function ok(): self
    {
        return new self(ZoneMetadataOutcome::OK);
    }

    public function isOk(): bool
    {
        return $this->outcome === ZoneMetadataOutcome::OK;
    }
}
