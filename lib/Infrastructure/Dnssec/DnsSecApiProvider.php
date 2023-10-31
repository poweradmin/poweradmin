<?php

namespace Poweradmin\Infrastructure\Dnssec;

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
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

// TODO:
// 1. Add syslog logging (if enabled)
// 2. Add debug logging (if enabled)
// 3. Better error handling (exceptions)
// 4. Move data transformation to separate class
// 5. Add tests (unit, integration, functional)
// 8. Update documentation

class DnsSecApiProvider implements DnssecProvider
{
    private PowerdnsApiClient $client;

    public function __construct(PowerdnsApiClient $client)
    {
        $this->client = $client;
    }

    public function rectifyZone(string $zone): bool
    {
        return $this->client->rectifyZone($zone);
    }

    public function secureZone(string $zone): bool
    {
        return $this->client->secureZone($zone);
    }

    public function unsecureZone(string $zone): bool
    {
        return $this->client->unsecureZone($zone);
    }

    public function isZoneSecured(string $zone): bool
    {
        return $this->client->isZoneSecured($zone);
    }

    public function getDsRecords(string $zone): array
    {
        $result = [];
        $keys = $this->client->getKeys($zone);
        foreach ($keys as $key) {
            foreach ($key["ds"] as $ds) {
                $result[] = $zone . ". IN DS " . $ds;
            }
        }
        return $result;
    }

    public function getDnsKeyRecords(string $zone): array
    {
        $result = [];
        $keys = $this->client->getKeys($zone);
        foreach ($keys as $key) {
            $result[] = $zone . ". IN DNSKEY " . $key["dnskey"];
        }
        return $result;
    }

    public function activateZoneKey(string $zone, int $keyId): bool
    {
        return $this->client->activateZoneKey($zone, $keyId);
    }

    public function deactivateZoneKey(string $zone, int $keyId): bool
    {
        return $this->client->deactivateZoneKey($zone, $keyId);
    }

    public function getKeys(string $zone): array
    {
        $result = [];
        $keys = $this->client->getKeys($zone);
        foreach ($keys as $key) {
            $result[] = $this->transformKey($key);
        }
        return $result;
    }

    public function addZoneKey(string $zone, string $keyType, int $keySize, string $algorithm): bool
    {
        return $this->client->addZoneKey($zone, $keyType, $keySize, $algorithm);
    }

    public function removeZoneKey(string $zone, int $keyId): bool
    {
        return $this->client->removeZoneKey($zone, $keyId);
    }

    public function keyExists(string $zone, int $keyId): bool
    {
        $keys = $this->client->getKeys($zone);

        foreach ($keys as $key) {
            if ($key['id'] === $keyId) {
                return true;
            }
        }
        return false;
    }

    public function getZoneKey(string $zone, int $keyId): array
    {
        $keys = $this->client->getKeys($zone);

        foreach ($keys as $key) {
            if ($key['id'] === $keyId) {
                return $this->transformKey($key);
            }
        }
        return [];
    }

    private function transformKey(mixed $key): array
    {
        $ds = explode(" ", $key['ds'][0] ?? "");
        $dnskey = explode(" ", $key['dnskey'] ?? "");

        [$dsValue] = $ds;
        [, , $dnsKeyValue] = $dnskey;

        return [
            $key['id'],
            strtoupper($key['keytype']),
            $dsValue,
            $dnsKeyValue,
            $key['bits'],
            $key['active'],
        ];
    }
}