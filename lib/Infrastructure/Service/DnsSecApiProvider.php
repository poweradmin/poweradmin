<?php

namespace Poweradmin\Infrastructure\Service;

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

use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Domain\Utility\DnssecTransformer;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Logger\LegacyLoggerInterface;

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
    private LegacyLoggerInterface $logger;
    private DnssecTransformer $transformer;
    private string $clientIp;
    private string $userLogin;

    public function __construct(
        PowerdnsApiClient     $client,
        LegacyLoggerInterface $logger,
        DnssecTransformer     $transformer,
        string                $clientIp,
        string                $userLogin
    )
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->transformer = $transformer;
        $this->clientIp = $clientIp;
        $this->userLogin = $userLogin;
    }

    public function rectifyZone(string $zoneName): bool
    {
        $zone = new Zone($zoneName);
        return $this->client->rectifyZone($zone);
    }

    public function secureZone(string $zoneName): bool
    {
        $zone = new Zone($zoneName);
        $result = $this->client->secureZone($zone);
        $this->logAction('dnssec_secure_zone', $zoneName, ['result' => $result]);
        return $result;
    }

    public function unsecureZone(string $zoneName): bool
    {
        $zone = new Zone($zoneName);
        $result = $this->client->unsecureZone($zone);
        $this->logAction('dnssec_unsecure_zone', $zoneName, ['result' => $result]);
        return $result;
    }

    public function isZoneSecured(string $zoneName, $config): bool
    {
        $zone = new Zone($zoneName);
        return $this->client->isZoneSecured($zone);
    }

    public function getDsRecords(string $zoneName): array
    {
        $zone = new Zone($zoneName);
        $keys = $this->client->getZoneKeys($zone);
        $result = [];
        foreach ($keys as $key) {
            foreach ($key->getDs() as $ds) {
                $result[] = $zoneName . ". IN DS " . $ds;
            }
        }
        return $result;
    }

    public function getDnsKeyRecords(string $zoneName): array
    {
        $zone = new Zone($zoneName);
        $keys = $this->client->getZoneKeys($zone);
        $result = [];
        foreach ($keys as $key) {
            $result[] = $zoneName . ". IN DNSKEY " . $key->getDnsKey();
        }
        return $result;
    }

    public function activateZoneKey(string $zoneName, int $keyId): bool
    {
        $zone = new Zone($zoneName);
        $key = new CryptoKey($keyId);
        $result = $this->client->activateZoneKey($zone, $key);
        $this->logAction('dnssec_activate_zone_key', $zoneName, ['keyId' => $keyId, 'result' => $result]);
        return $result;
    }

    public function deactivateZoneKey(string $zoneName, int $keyId): bool
    {
        $zone = new Zone($zoneName);
        $key = new CryptoKey($keyId);
        $result = $this->client->deactivateZoneKey($zone, $key);
        $this->logAction('dnssec_deactivate_zone_key', $zoneName, ['keyId' => $keyId, 'result' => $result]);
        return $result;
    }

    public function getKeys(string $zoneName): array
    {
        $zone = new Zone($zoneName);
        $keys = $this->client->getZoneKeys($zone);
        return array_map([$this->transformer, 'transformKey'], $keys);
    }

    public function addZoneKey(string $zoneName, string $keyType, int $keySize, string $algorithm): bool
    {
        $zone = new Zone($zoneName);
        $key = new CryptoKey(null, $keyType, $keySize, $algorithm);
        $result = $this->client->addZoneKey($zone, $key);
        $this->logAction('dnssec_add_zone_key', $zoneName, ['type' => $keyType, 'bits' => $keySize, 'algorithm' => $algorithm, 'result' => $result]);
        return $result;
    }

    public function removeZoneKey(string $zoneName, int $keyId): bool
    {
        $zone = new Zone($zoneName);
        $key = new CryptoKey($keyId);
        $result = $this->client->removeZoneKey($zone, $key);
        $this->logAction('dnssec_remove_zone_key', $zoneName, ['keyId' => $keyId, 'result' => $result]);
        return $result;
    }

    public function keyExists(string $zoneName, int $keyId): bool
    {
        $zone = new Zone($zoneName);
        $keys = $this->client->getZoneKeys($zone);
        foreach ($keys as $key) {
            if ($key->getId() === $keyId) {
                return true;
            }
        }
        return false;
    }

    public function getZoneKey(string $zoneName, int $keyId): array
    {
        $zone = new Zone($zoneName);
        $keys = $this->client->getZoneKeys($zone);
        foreach ($keys as $key) {
            if ($key->getId() === $keyId) {
                return $this->transformer->transformKey($key);
            }
        }
        return [];
    }

    public function isDnssecEnabled(): bool
    {
        $serverConfig = $this->client->getConfig();

        foreach ($serverConfig as $item) {
            if (str_ends_with($item['name'], '-dnssec') && $item['value'] !== 'no') {
                return true;
            }
        }

        return false;
    }

    private function logAction(string $action, string $zoneName, array $context = []): void
    {
        $contextString = [];
        foreach ($context as $key => $value) {
            $contextString[] = "$key:$value";
        }
        $formattedContext = implode(' ', $contextString);

        $this->logInfo("client_ip:$this->clientIp user:$this->userLogin operation:$action zone:$zoneName $formattedContext");
    }
}