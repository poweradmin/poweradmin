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

namespace Poweradmin\Domain\Port;

use Poweradmin\Domain\Model\CryptoKey;

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
    public function removeZoneKey(string $zoneName, int $keyId): bool;

    /**
     * The zone's keys, telling a server that could not be asked apart from a zone without keys.
     *
     * @return CryptoKey[]|null Null when the server could not be asked
     * @phpstan-impure
     */
    public function fetchZoneKeys(string $zoneName): ?array;

    /**
     * Create a key and return it as the server created it, or null when it was refused or could not be asked.
     */
    public function createZoneKey(string $zoneName, string $keyType, int $keySize, string $algorithm, bool $active): ?CryptoKey;

    /**
     * Import a DNSSEC key from a PEM-encoded private key. Requires PowerDNS
     * 4.7+; older servers should return false. Implementations that do not
     * back onto the PowerDNS API may also return false.
     */
    public function importZoneKey(string $zoneName, string $keyType, string $algorithm, #[\SensitiveParameter] string $privateKeyPem): bool;

    /**
     * Export the PEM-encoded private key for an existing cryptokey, or null
     * when the server does not support PEM export (pre-4.7) or the key
     * cannot be read.
     */
    public function exportZoneKeyPem(string $zoneName, int $keyId): ?string;
}
