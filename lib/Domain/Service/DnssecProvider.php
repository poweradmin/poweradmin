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

namespace Poweradmin\Domain\Service;

interface DnssecProvider {
    public function rectifyZone(string $zoneName): bool;
    public function secureZone(string $zoneName): bool;
    public function unsecureZone(string $zoneName): bool;
    public function isZoneSecured(string $zoneName, $config): bool;
    public function getDsRecords(string $zoneName): array;
    public function getDnsKeyRecords(string $zoneName): array;
    public function activateZoneKey(string $zoneName, int $keyId): bool;
    public function deactivateZoneKey(string $zoneName, int $keyId): bool;
    public function getKeys(string $zoneName): array;
    public function addZoneKey(string $zoneName, string $keyType, int $keySize, string $algorithm): bool;
    public function removeZoneKey(string $zoneName, int $keyId): bool;
    public function keyExists(string $zoneName, int $keyId): bool;
    public function getZoneKey(string $zoneName, int $keyId): array;
    public function isDnssecEnabled(): bool;
}
