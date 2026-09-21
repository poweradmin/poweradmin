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

use Poweradmin\Domain\Service\ZoneSigningResult;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * What ZoneCreateService made of one request: the created zone, or the
 * refusal worded for the form.
 */
final readonly class ZoneCreateOutcome
{
    /**
     * @param string $zoneName The stored (punycode) name, '' when refused before it was settled
     * @param ZoneSigningResult|null $dnssec The signing result when signing was requested
     * @param string|null $message The refusal in the user's language, null on success
     */
    private function __construct(
        public bool $success,
        public ?int $zoneId,
        public string $zoneName,
        public ?ZoneSigningResult $dnssec,
        public ?string $message
    ) {
    }

    public static function created(int $zoneId, string $zoneName, ?ZoneSigningResult $dnssec = null): self
    {
        return new self(true, $zoneId, $zoneName, $dnssec, null);
    }

    public static function refused(string $message, string $zoneName = ''): self
    {
        return new self(false, null, $zoneName, null, $message);
    }

    public function isReverseZone(): bool
    {
        return DnsHelper::isReverseZoneName($this->zoneName);
    }
}
