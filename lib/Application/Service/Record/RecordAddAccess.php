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

namespace Poweradmin\Application\Service\Record;

/**
 * Whether a user may add records to a zone directly, as RecordAddService::open()
 * answers it. Each add-record entry point words the refusal codes itself.
 */
final readonly class RecordAddAccess
{
    public const OK = 'ok';
    public const ZONE_NOT_FOUND = 'zone_not_found';
    public const REQUIRES_APPROVAL = 'requires_approval';
    public const FORBIDDEN = 'forbidden';

    /**
     * @param string $code One of the class constants
     * @param string $zoneName The stored zone name, '' when the zone was not found
     * @param string $zoneType MASTER, SLAVE, NATIVE, ..., '' when the zone was not found
     */
    private function __construct(
        public string $code,
        public string $zoneName,
        public string $zoneType
    ) {
    }

    public static function granted(string $zoneName, string $zoneType): self
    {
        return new self(self::OK, $zoneName, $zoneType);
    }

    public static function zoneNotFound(): self
    {
        return new self(self::ZONE_NOT_FOUND, '', '');
    }

    public static function refused(string $code, string $zoneName, string $zoneType): self
    {
        return new self($code, $zoneName, $zoneType);
    }

    public function isGranted(): bool
    {
        return $this->code === self::OK;
    }
}
