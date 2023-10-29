<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

use Poweradmin\Domain\Dnssec\DnssecProvider;

class PdnsApiProvider implements DnssecProvider
{
    public function rectifyZone(int $domainId): bool
    {
        // TODO: Implement rectifyZone() method.
    }

    public function secureZone(string $domainName): bool
    {
        // TODO: Implement secureZone() method.
    }

    public function unsecureZone(string $domainName): bool
    {
        // TODO: Implement unsecureZone() method.
    }

    public function isZoneSecured(string $domainName): bool
    {
        // TODO: Implement isZoneSecured() method.
    }

    public function getDsRecords(string $domainName): array
    {
        // TODO: Implement getDsRecords() method.
    }

    public function getDnsKeyRecords(string $domainName): array
    {
        // TODO: Implement getDnsKeyRecords() method.
    }

    public function activateZoneKey(string $domainName, int $keyId): bool
    {
        // TODO: Implement activateZoneKey() method.
    }

    public function deactivateZoneKey(string $domainName, int $keyId): bool
    {
        // TODO: Implement deactivateZoneKey() method.
    }

    public function getKeys(string $domainName): array
    {
        // TODO: Implement getKeys() method.
    }

    public function addZoneKey(string $domainName, string $keyType, int $keySize, string $algorithm): bool
    {
        // TODO: Implement addZoneKey() method.
    }

    public function removeZoneKey(string $domainName, int $keyId): bool
    {
        // TODO: Implement removeZoneKey() method.
    }

    public function keyExists(string $domainName, int $keyId): bool
    {
        // TODO: Implement keyExists() method.
    }

    public function getZoneKey(string $domainName, int $keyId): array
    {
        // TODO: Implement getZoneKey() method.
    }
}