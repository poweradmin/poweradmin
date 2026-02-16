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

namespace Poweradmin\Module\ZoneImportExport\Service;

class ParsedZoneFile
{
    /** @var string|null */
    private ?string $origin;

    /** @var int */
    private int $defaultTtl;

    /** @var ParsedRecord[] */
    private array $records;

    /** @var string[] */
    private array $warnings;

    /**
     * @param string|null $origin
     * @param int $defaultTtl
     * @param ParsedRecord[] $records
     * @param string[] $warnings
     */
    public function __construct(?string $origin, int $defaultTtl, array $records, array $warnings = [])
    {
        $this->origin = $origin;
        $this->defaultTtl = $defaultTtl;
        $this->records = $records;
        $this->warnings = $warnings;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * @return ParsedRecord[]
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getRecordCount(): int
    {
        return count($this->records);
    }
}
