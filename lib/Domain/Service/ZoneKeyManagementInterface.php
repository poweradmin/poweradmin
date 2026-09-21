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

namespace Poweradmin\Domain\Service;

/**
 * DNSSEC key lifecycle for a zone, plus the DS and DNSKEY records derived from its keys.
 */
interface ZoneKeyManagementInterface
{
    public function getDsRecords(string $zoneName): array;
    public function getDnsKeyRecords(string $zoneName): array;
    public function activateZoneKey(string $zoneName, int $keyId): bool;
    public function deactivateZoneKey(string $zoneName, int $keyId): bool;
    public function getKeys(string $zoneName): array;
    public function addZoneKey(string $zoneName, string $keyType, int $keySize, string $algorithm): bool;
    public function removeZoneKey(string $zoneName, int $keyId): bool;
    /**
     * Asks the server each time; a key can vanish between two calls, so the result is not remembered.
     *
     * @phpstan-impure
     */
    public function keyExists(string $zoneName, int $keyId): bool;
    public function getZoneKey(string $zoneName, int $keyId): array;

    /**
     * Import a DNSSEC key from a PEM-encoded private key. Requires PowerDNS
     * 4.7+; older servers should return false. Implementations that do not
     * back onto the PowerDNS API may also return false.
     */
    public function importZoneKey(string $zoneName, string $keyType, string $algorithm, string $privateKeyPem): bool;

    /**
     * Export the PEM-encoded private key for an existing cryptokey, or null
     * when the server does not support PEM export (pre-4.7) or the key
     * cannot be read.
     */
    public function exportZoneKeyPem(string $zoneName, int $keyId): ?string;
}
