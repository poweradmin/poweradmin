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


namespace Poweradmin\Domain\Enum;

/**
 * Apex SOA health of a zone, as shown by the badge on the zone lists.
 *
 * Replaces an `array{is_disabled: bool, is_missing_soa: bool}|null` that was
 * assembled by hand at eight sites. The pair could express "SOA is disabled"
 * and "there is no SOA" at once, and every consumer collapsed the null with
 * `?? false`, which made a backend outage look like a healthy zone.
 */
enum ZoneSoaHealth: string
{
    case OK = 'ok';
    case SOA_DISABLED = 'soa_disabled';
    case SOA_MISSING = 'soa_missing';

    /** The backend could not be reached, so health is genuinely not known. */
    case UNKNOWN = 'unknown';

    /**
     * Classify what a DnsBackendProvider returned. Null means the lookup
     * failed, which is UNKNOWN rather than healthy.
     */
    public static function fromBackend(?array $soaHealth): self
    {
        if ($soaHealth === null) {
            return self::UNKNOWN;
        }

        if (!empty($soaHealth['is_disabled'])) {
            return self::SOA_DISABLED;
        }

        return empty($soaHealth['is_missing_soa']) ? self::OK : self::SOA_MISSING;
    }

    public function isDisabled(): bool
    {
        return $this === self::SOA_DISABLED;
    }

    public function isMissingSoa(): bool
    {
        return $this === self::SOA_MISSING;
    }

    /**
     * The legacy pair, for the array shape the zone lists still consume.
     *
     * @return array{is_disabled: bool, is_missing_soa: bool, soa_health: string}
     */
    public function toZoneFields(): array
    {
        return [
            'is_disabled' => $this->isDisabled(),
            'is_missing_soa' => $this->isMissingSoa(),
            'soa_health' => $this->value,
        ];
    }
}
