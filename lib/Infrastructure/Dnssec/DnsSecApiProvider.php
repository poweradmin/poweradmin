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
use Poweradmin\Domain\Transformer\DnssecTransformer;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Logger\LoggerInterface;

// TODO:
// - Add debug logging (if enabled)
// - Better error handling (visual response)
// - Add tests (unit, integration, functional)
// - Provide documentation
// - Test syslog logging
// - Move logging into middleware, decorator, or event listener/subscriber
// - define interfaces or DTOs for returned data by PowerDNS API

class DnsSecApiProvider implements DnssecProvider
{
    private PowerdnsApiClient $client;
    private LoggerInterface $logger;
    private DnssecTransformer $transformer;
    private string $clientIp;
    private string $userLogin;

    public function __construct(
        PowerdnsApiClient $client,
        LoggerInterface $logger,
        DnssecTransformer $transformer,
        string $clientIp,
        string $userLogin
    )
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->transformer = $transformer;
        $this->clientIp = $clientIp;
        $this->userLogin = $userLogin;
    }

    public function rectifyZone(string $zone): bool
    {
        return $this->client->rectifyZone($zone);
    }

    public function secureZone(string $zone): bool
    {
        $result = $this->client->secureZone($zone);

        $this->logger->info("client_ip:{$this->clientIp} user:{$this->userLogin} operation:dnssec_secure_zone zone:{$zone} result:{$result}");

        return $result;
    }

    public function unsecureZone(string $zone): bool
    {
        $result = $this->client->unsecureZone($zone);

        $this->logger->info("client_ip:{$this->clientIp} user:{$this->userLogin} operation:dnssec_unsecure_zone zone:{$zone} result:{$result}");

        return $result;
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
        $result = $this->client->activateZoneKey($zone, $keyId);

        $this->logger->info("client_ip:{$this->clientIp} user:{$this->userLogin} operation:dnssec_activate_zone_key zone:{$zone} key_id:{$keyId} result:{$result}");

        return $result;
    }

    public function deactivateZoneKey(string $zone, int $keyId): bool
    {
        $result = $this->client->deactivateZoneKey($zone, $keyId);

        $this->logger->info("client_ip:{$this->clientIp} user:{$this->userLogin} operation:dnssec_deactivate_zone_key zone:{$zone} key_id:{$keyId} result:{$result}");

        return $result;
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
        $result = $this->client->addZoneKey($zone, $keyType, $keySize, $algorithm);

        $this->logger->info("client_ip:{$this->clientIp} user:{$this->userLogin} operation:dnssec_add_zone_key zone:{$zone} type:{$keyType} bits:{$keySize} algorithm:{$algorithm} result:{$result}");

        return $result;
    }

    public function removeZoneKey(string $zone, int $keyId): bool
    {
        $result = $this->client->removeZoneKey($zone, $keyId);

        $this->logger->info("client_ip:{$this->clientIp} user:{$this->userLogin} operation:dnssec_remove_zone_key zone:{$zone} key_id:{$keyId} result:{$result}");

        return $result;
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
        return $this->transformer->transformKey($key);
    }
}