<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Infrastructure\Service;

use Poweradmin\Domain\Service\DnssecProvider;

/**
 * Null Object implementation of DnssecProvider
 * Used when PowerDNS API is not configured or DNSSEC is disabled
 */
class NullDnssecProvider implements DnssecProvider
{
    public function rectifyZone(string $zoneName): bool
    {
        return false;
    }

    public function secureZone(string $zoneName): bool
    {
        return false;
    }

    public function unsecureZone(string $zoneName): bool
    {
        return false;
    }

    public function isZoneSecured(string $zoneName, $config): bool
    {
        return false;
    }

    public function getDsRecords(string $zoneName): array
    {
        return [];
    }

    public function getDnsKeyRecords(string $zoneName): array
    {
        return [];
    }

    public function activateZoneKey(string $zoneName, int $keyId): bool
    {
        return false;
    }

    public function deactivateZoneKey(string $zoneName, int $keyId): bool
    {
        return false;
    }

    public function getKeys(string $zoneName): array
    {
        return [];
    }

    public function addZoneKey(string $zoneName, string $keyType, int $keySize, string $algorithm): bool
    {
        return false;
    }

    public function removeZoneKey(string $zoneName, int $keyId): bool
    {
        return false;
    }

    public function keyExists(string $zoneName, int $keyId): bool
    {
        return false;
    }

    public function getZoneKey(string $zoneName, int $keyId): array
    {
        return [];
    }

    public function isDnssecEnabled(): bool
    {
        return false;
    }
}
