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

namespace Poweradmin\Domain\Dnssec;

interface DnssecProvider {
    public function rectifyZone(string $zone): bool;
    public function secureZone(string $zone): bool;
    public function unsecureZone(string $zone): bool;
    public function isZoneSecured(string $zone): bool;
    public function getDsRecords(string $zone): array;
    public function getDnsKeyRecords(string $zone): array;
    public function activateZoneKey(string $zone, int $keyId): bool;
    public function deactivateZoneKey(string $zone, int $keyId): bool;
    public function getKeys(string $zone): array;
    public function addZoneKey(string $zone, string $keyType, int $keySize, string $algorithm): bool;
    public function removeZoneKey(string $zone, int $keyId): bool;
    public function keyExists(string $zone, int $keyId): bool;
    public function getZoneKey(string $zone, int $keyId): array;
}
