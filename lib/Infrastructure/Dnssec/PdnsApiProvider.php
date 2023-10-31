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
use Poweradmin\LegacyConfiguration;

class PdnsApiProvider implements DnssecProvider
{
    private PowerdnsApiClient $client;

    public function __construct(PowerdnsApiClient $client)
    {
        $this->client = $client;
    }

    public function rectifyZone(string $domainName): bool
    {
        $response = $this->client->rectifyZone($domainName);
        return $response && $response['result'] === 'Rectified';
    }

    public function secureZone(string $domainName): bool
    {
        return $this->client->secureZone($domainName);
    }

    public function unsecureZone(string $domainName): bool
    {
        return $this->client->unsecureZone($domainName);
    }

    public function isZoneSecured(string $domainName): bool
    {
        return $this->client->isZoneSecured($domainName);
    }

    public function getDsRecords(string $domainName): array
    {
        $result = [];
        $keys = $this->client->getKeys($domainName);
        foreach ($keys as $key) {
            foreach ($key["ds"] as $ds) {
                $result[] = $domainName . ". IN DS " . $ds;
            }
        }
        return $result;
    }

    public function getDnsKeyRecords(string $domainName): array
    {
        $result = [];
        $keys = $this->client->getKeys($domainName);
        foreach ($keys as $key) {
            $result[] = $domainName . ". IN DNSKEY " . $key["dnskey"];
        }
        return $result;
    }

    public function activateZoneKey(string $domainName, int $keyId): bool
    {
        return $this->client->activateZoneKey($domainName, $keyId);
    }

    public function deactivateZoneKey(string $domainName, int $keyId): bool
    {
        return $this->client->deactivateZoneKey($domainName, $keyId);
    }

    public function getKeys(string $domainName): array
    {
        $keys = $this->client->getKeys($domainName);

        // TODO: review this mapping
        $result = [];
        foreach ($keys as $key) {
            $ds = explode(" ", $key['ds'][0] ?? "");
            $dnskey = explode(" ", $key['dnskey'] ?? "");

            [$dsValue] = $ds;
            [,, $dnsKeyValue] = $dnskey;

            $result[] = [
                $key['id'],
                strtoupper($key['keytype']),
                $dsValue,
                $dnsKeyValue,
                $key['bits'],
                $key['active'],
            ];
        }
        return $result;
    }

    public function addZoneKey(string $domainName, string $keyType, int $keySize, string $algorithm): bool
    {
        return $this->client->addZoneKey($domainName, $keyType, $keySize, $algorithm);
    }

    public function removeZoneKey(string $domainName, int $keyId): bool
    {
        return $this->client->removeZoneKey($domainName, $keyId);
    }

    public function keyExists(string $domainName, int $keyId): bool
    {
        $keys = $this->client->getKeys($domainName);

        foreach ($keys as $key) {
            if ($key['id'] === $keyId) {
                return true;
            }
        }
        return false;
    }

    public function getZoneKey(string $domainName, int $keyId): array
    {
        $keys = $this->client->getKeys($domainName);

        foreach ($keys as $key) {
            if ($key['id'] === $keyId) {
                $ds = explode(" ", $key['ds'][0] ?? "");
                $dnskey = explode(" ", $key['dnskey'] ?? "");

                [$dsValue] = $ds;
                [,, $dnsKeyValue] = $dnskey;

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
        return [];
    }
}