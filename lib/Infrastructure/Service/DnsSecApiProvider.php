<?php

namespace Poweradmin\Infrastructure\Service;

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

use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Utility\DnssecDataTransformer;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Psr\Log\LoggerInterface;

/**
 * DnssecProviderInterface that manages zone keys, DS/DNSKEY records and rectification through the PowerDNS API.
 */
final class DnsSecApiProvider implements DnssecProviderInterface
{
    private PowerdnsApiClient $client;
    private LoggerInterface $logger;
    private DnssecDataTransformer $transformer;
    private string $clientIp;
    private string $userLogin;

    /** @var array<string, bool> Per-request cache to avoid repeated metadata lookups */
    private array $presignedByZone = [];

    public function __construct(
        PowerdnsApiClient $client,
        LoggerInterface $logger,
        DnssecDataTransformer $transformer,
        string $clientIp,
        string $userLogin
    ) {
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
        try {
            $zone = new Zone($zoneName);
            return $this->client->isZoneSecured($zone);
        } catch (ApiErrorException $e) {
            // Return false instead of crashing when API call fails
            // (e.g., due to invalid record data in the zone)
            return false;
        }
    }

    public function isZonePresigned(string $zoneName): bool
    {
        if (!array_key_exists($zoneName, $this->presignedByZone)) {
            // PowerDNS signals presignedness only when the metadata content is exactly "1"
            $meta = $this->client->getZoneMetadataKind(new Zone($zoneName), 'PRESIGNED');
            $this->presignedByZone[$zoneName] = in_array('1', $meta['metadata'] ?? [], true);
        }
        return $this->presignedByZone[$zoneName];
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

    public function removeZoneKey(string $zoneName, int $keyId): bool
    {
        $zone = new Zone($zoneName);
        $key = new CryptoKey($keyId);
        $result = $this->client->removeZoneKey($zone, $key);
        $this->logAction('dnssec_remove_zone_key', $zoneName, ['keyId' => $keyId, 'result' => $result]);
        return $result;
    }

    public function fetchZoneKeys(string $zoneName): ?array
    {
        return $this->client->fetchZoneKeys(new Zone($zoneName));
    }

    public function createZoneKey(string $zoneName, string $keyType, int $keySize, string $algorithm, bool $active): ?CryptoKey
    {
        $created = $this->client->createZoneKey(new Zone($zoneName), new CryptoKey(null, $keyType, $keySize, $algorithm), $active);
        $this->logAction('dnssec_add_zone_key', $zoneName, ['type' => $keyType, 'bits' => $keySize, 'algorithm' => $algorithm, 'result' => $created !== null]);
        return $created;
    }

    public function isDnssecEnabled(): bool
    {
        $serverConfig = $this->client->getConfig();

        foreach ($serverConfig as $item) {
            $name = (string)($item['name'] ?? '');
            $value = (string)($item['value'] ?? '');
            if (str_ends_with($name, '-dnssec') && $value !== 'no') {
                return true;
            }
            // bind and geoip turn DNSSEC on by naming a key store rather than with a *-dnssec switch
            if (in_array($name, ['bind-dnssec-db', 'geoip-dnssec-keydir'], true) && $value !== '') {
                return true;
            }
            // The LMDB backend stores DNSSEC data natively and has no *-dnssec switch
            if ($name === 'launch' && preg_match('/(^|[\s,])lmdb(:|[\s,]|$)/', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public function getEditedSerial(string $zoneName): ?int
    {
        $zoneData = $this->client->getZone(rtrim($zoneName, '.') . '.', false);
        // Unsigned zones serve the plain serial, so there is no signed serial to report
        if (!($zoneData['dnssec'] ?? false) || !isset($zoneData['edited_serial'])) {
            return null;
        }
        return (int)$zoneData['edited_serial'];
    }

    public function importZoneKeyFromPrivateKey(
        string $zoneName,
        string $keyType,
        #[\SensitiveParameter] string $privateKey,
        bool $active
    ): CryptoKey|DnssecKeyOutcome {
        $created = $this->client->createZoneKeyFromPrivateKey(new Zone($zoneName), $keyType, $privateKey, $active);
        // Never log the private key itself
        $this->logAction('dnssec_import_zone_key', $zoneName, ['type' => $keyType, 'active' => $active ? 'yes' : 'no', 'result' => $created instanceof CryptoKey]);
        return $created;
    }

    public function importZoneKey(string $zoneName, string $keyType, string $algorithm, #[\SensitiveParameter] string $privateKeyPem): bool
    {
        $zone = new Zone($zoneName);
        $result = $this->client->importZoneKey($zone, $keyType, $algorithm, $privateKeyPem);
        $this->logAction('dnssec_import_zone_key', $zoneName, ['type' => $keyType, 'algorithm' => $algorithm, 'result' => $result]);
        return $result;
    }

    public function exportZoneKeyPem(string $zoneName, int $keyId): ?string
    {
        $zone = new Zone($zoneName);
        $payload = $this->client->getZoneKeyWithPrivate($zone, $keyId);
        if ($payload === null) {
            return null;
        }
        $pem = $payload['privatekey'] ?? null;
        if (!is_string($pem) || $pem === '') {
            return null;
        }
        $this->logAction('dnssec_export_zone_key', $zoneName, ['keyId' => $keyId]);
        return $pem;
    }

    private function logAction(string $action, string $zoneName, array $context = []): void
    {
        $contextString = [];
        foreach ($context as $key => $value) {
            $contextString[] = "$key:$value";
        }
        $formattedContext = implode(' ', $contextString);

        $this->logger->info("client_ip:$this->clientIp user:$this->userLogin operation:$action zone:$zoneName $formattedContext");
    }
}
