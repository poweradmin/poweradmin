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
    public function rectifyZone(int $domainId): bool;
    public function secureZone(string $domainName): bool;
    public function unsecureZone(string $domainName): bool;
    public function isZoneSecured(string $domainName): bool;
    public function getDsRecords(string $domainName): array;
    public function getDnsKeyRecords(string $domainName): array;
    public function activateZoneKey(string $domainName, int $keyId): bool;
    public function deactivateZoneKey(string $domainName, int $keyId): bool;
    public function getKeys(string $domainName): array;
    public function addZoneKey(string $domainName, string $keyType, int $keySize, string $algorithm): bool;
    public function removeZoneKey(string $domainName, int $keyId): bool;
    public function keyExists(string $domainName, int $keyId): bool;
    public function getZoneKey(string $domainName, int $keyId): array;
}
