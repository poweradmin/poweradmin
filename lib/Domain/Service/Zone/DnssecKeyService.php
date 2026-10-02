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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Enum\DnssecKeyType;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Port\ZoneKeyManagementInterface;
use Poweradmin\Domain\Port\ZoneSigningInterface;

/**
 * Lists, adds, activates, deactivates and removes the DNSSEC keys of a zone
 * the one way the web key pages and the API agree on: validate a new key
 * against what the server offers, refuse changes when PowerDNS cannot be
 * asked, has DNSSEC off or the zone is presigned, and audit what changed.
 */
class DnssecKeyService
{
    /** Generous upper bound for an ISC private key; an RSA-4096 key is about 3.3 KB */
    private const MAX_PRIVATE_KEY_BYTES = 16384;

    public function __construct(
        private readonly ZoneKeyManagementInterface&ZoneSigningInterface $dnssec,
        private readonly AuditLoggerInterface $audit
    ) {
    }

    public function listKeys(string $zoneName): DnssecKeyResult
    {
        $keys = $this->dnssec->fetchZoneKeys($zoneName);
        if ($keys === null) {
            return new DnssecKeyResult(DnssecKeyOutcome::UNREACHABLE);
        }

        return new DnssecKeyResult(DnssecKeyOutcome::LISTED, keys: $keys);
    }

    public function findKey(string $zoneName, int $keyId): DnssecKeyResult
    {
        $keys = $this->dnssec->fetchZoneKeys($zoneName);
        if ($keys === null) {
            return new DnssecKeyResult(DnssecKeyOutcome::UNREACHABLE);
        }

        return self::keyIn($keys, $keyId);
    }

    /**
     * Check a new key's type, algorithm and size, in that order, without asking PowerDNS.
     *
     * @param int|null $bits Null when the caller could not read a number
     * @param PdnsCapabilities|null $capabilities The connected server, which decides the algorithms offered
     * @return DnssecKeyResult|null The refusal, or null when the key may be added
     */
    public function validateNewKey(string $type, string $algorithm, ?int $bits, ?PdnsCapabilities $capabilities): ?DnssecKeyResult
    {
        if (!DnssecKeyType::isValid($type)) {
            return new DnssecKeyResult(DnssecKeyOutcome::INVALID_TYPE);
        }

        $allowedAlgorithms = array_values(DnssecAlgorithmName::getSupportedAlgorithmsForCapabilities($capabilities));
        if (!in_array($algorithm, $allowedAlgorithms, true)) {
            return new DnssecKeyResult(DnssecKeyOutcome::INVALID_ALGORITHM, allowedAlgorithms: $allowedAlgorithms);
        }

        $acceptedBits = DnssecAlgorithmName::ALGORITHM_BITS[$algorithm];
        if ($bits === null || !in_array($bits, $acceptedBits, true)) {
            return new DnssecKeyResult(DnssecKeyOutcome::INVALID_BITS, acceptedBits: $acceptedBits);
        }

        return null;
    }

    public function addKey(
        int $zoneId,
        string $zoneName,
        string $type,
        string $algorithm,
        int $bits,
        bool $active,
        ?PdnsCapabilities $capabilities
    ): DnssecKeyResult {
        $invalid = $this->validateNewKey($type, $algorithm, $bits, $capabilities);
        if ($invalid !== null) {
            return $invalid;
        }

        $keys = $this->writableKeys($zoneName);
        if ($keys instanceof DnssecKeyResult) {
            return $keys;
        }

        $created = $this->dnssec->createZoneKey($zoneName, $type, $bits, $algorithm, $active);
        if ($created === null) {
            return new DnssecKeyResult(DnssecKeyOutcome::FAILED);
        }

        $this->audit->logDnssecAddKey($zoneId, $zoneName, $type, (string)$bits, $algorithm);

        return new DnssecKeyResult(DnssecKeyOutcome::ADDED, $created);
    }

    /**
     * Check that a private key looks like the ISC/BIND format PowerDNS imports
     * ("Private-key-format: v1.x" and an "Algorithm:" line), without asking
     * PowerDNS. PEM keys are refused: the PowerDNS HTTP API does not take them.
     */
    public static function isIscPrivateKey(#[\SensitiveParameter] string $privateKey): bool
    {
        $privateKey = trim($privateKey);
        if ($privateKey === '' || strlen($privateKey) > self::MAX_PRIVATE_KEY_BYTES) {
            return false;
        }

        return preg_match('/^Private-key-format:\s*v1\.\d+\s*$/mi', $privateKey) === 1
            && preg_match('/^Algorithm:\s*\d+/mi', $privateKey) === 1;
    }

    /**
     * Import a key from an existing ISC/BIND private key. PowerDNS derives the
     * algorithm and size from the key; the created key carries them. The key's
     * algorithm must be one offered for new keys.
     *
     * The private key is only passed on to PowerDNS: it is not audited, logged
     * or part of the result.
     *
     * @param PdnsCapabilities|null $capabilities The connected server, which decides the algorithms accepted
     */
    public function importKey(
        int $zoneId,
        string $zoneName,
        string $type,
        #[\SensitiveParameter] string $privateKey,
        bool $active,
        ?PdnsCapabilities $capabilities = null
    ): DnssecKeyResult {
        if (!DnssecKeyType::isValid($type)) {
            return new DnssecKeyResult(DnssecKeyOutcome::INVALID_TYPE);
        }
        if (!self::isIscPrivateKey($privateKey)) {
            return new DnssecKeyResult(DnssecKeyOutcome::INVALID_PRIVATE_KEY);
        }

        $allowedAlgorithms = array_values(DnssecAlgorithmName::getSupportedAlgorithmsForCapabilities($capabilities));
        if (
            preg_match('/^Algorithm:\s*(\d+)/mi', trim($privateKey), $match) !== 1
            || !in_array(DnssecAlgorithmName::fromAlgorithmId((int)$match[1]), $allowedAlgorithms, true)
        ) {
            return new DnssecKeyResult(DnssecKeyOutcome::INVALID_ALGORITHM, allowedAlgorithms: $allowedAlgorithms);
        }

        $keys = $this->writableKeys($zoneName);
        if ($keys instanceof DnssecKeyResult) {
            return $keys;
        }

        $created = $this->dnssec->importZoneKeyFromPrivateKey($zoneName, $type, trim($privateKey) . "\n", $active);
        if ($created instanceof DnssecKeyOutcome) {
            // Only KEY_REJECTED or FAILED; anything else would be a provider bug, so treat it as a failure
            return new DnssecKeyResult($created === DnssecKeyOutcome::KEY_REJECTED ? DnssecKeyOutcome::KEY_REJECTED : DnssecKeyOutcome::FAILED);
        }

        $this->audit->logDnssecAddKey(
            $zoneId,
            $zoneName,
            $type,
            (string)$created->getSize(),
            DnssecAlgorithmName::fromAlgorithmId($created->getAlgorithmId()) ?? (string)$created->getAlgorithmId()
        );

        return new DnssecKeyResult(DnssecKeyOutcome::ADDED, $created);
    }

    public function setKeyActive(int $zoneId, string $zoneName, int $keyId, bool $active): DnssecKeyResult
    {
        return $this->changeKeyState($zoneId, $zoneName, $keyId, $active);
    }

    /**
     * Activate an inactive key or deactivate an active one; the result's key tells which.
     */
    public function toggleKey(int $zoneId, string $zoneName, int $keyId): DnssecKeyResult
    {
        return $this->changeKeyState($zoneId, $zoneName, $keyId, null);
    }

    public function removeKey(int $zoneId, string $zoneName, int $keyId): DnssecKeyResult
    {
        $key = $this->writableKey($zoneName, $keyId);
        if ($key instanceof DnssecKeyResult) {
            return $key;
        }

        if (!$this->dnssec->removeZoneKey($zoneName, $keyId)) {
            return new DnssecKeyResult(DnssecKeyOutcome::FAILED, $key);
        }

        $this->audit->logDnssecDeleteKey($zoneId, $zoneName, $keyId);

        return new DnssecKeyResult(DnssecKeyOutcome::REMOVED, $key);
    }

    /**
     * @param bool|null $active The state wanted, or null to flip the current one
     */
    private function changeKeyState(int $zoneId, string $zoneName, int $keyId, ?bool $active): DnssecKeyResult
    {
        $key = $this->writableKey($zoneName, $keyId);
        if ($key instanceof DnssecKeyResult) {
            return $key;
        }

        $active ??= !$key->isActive();
        if ($key->isActive() === $active) {
            return new DnssecKeyResult(DnssecKeyOutcome::UNCHANGED, $key);
        }

        $changed = $active
            ? $this->dnssec->activateZoneKey($zoneName, $keyId)
            : $this->dnssec->deactivateZoneKey($zoneName, $keyId);
        if (!$changed) {
            return new DnssecKeyResult(DnssecKeyOutcome::FAILED, $key);
        }

        $this->audit->logDnssecToggleKey($zoneId, $zoneName, $keyId, $active ? 'activate' : 'deactivate');
        $active ? $key->activate() : $key->deactivate();

        return new DnssecKeyResult(DnssecKeyOutcome::UPDATED, $key);
    }

    private function writableKey(string $zoneName, int $keyId): CryptoKey|DnssecKeyResult
    {
        $keys = $this->writableKeys($zoneName);
        if ($keys instanceof DnssecKeyResult) {
            return $keys;
        }

        $found = self::keyIn($keys, $keyId);

        return $found->key ?? $found;
    }

    /**
     * The zone's keys when they may be changed, or the refusal.
     *
     * @return CryptoKey[]|DnssecKeyResult
     */
    private function writableKeys(string $zoneName): array|DnssecKeyResult
    {
        // Asked first: the server-settings lookup below reads a failed request as "DNSSEC off"
        $keys = $this->dnssec->fetchZoneKeys($zoneName);
        if ($keys === null) {
            return new DnssecKeyResult(DnssecKeyOutcome::UNREACHABLE);
        }
        if (!$this->dnssec->isDnssecEnabled()) {
            return new DnssecKeyResult(DnssecKeyOutcome::SERVER_DISABLED);
        }
        if ($this->dnssec->isZonePresigned($zoneName)) {
            return new DnssecKeyResult(DnssecKeyOutcome::PRESIGNED);
        }

        return $keys;
    }

    /**
     * @param CryptoKey[] $keys
     */
    private static function keyIn(array $keys, int $keyId): DnssecKeyResult
    {
        foreach ($keys as $key) {
            if ($key->getId() === $keyId) {
                return new DnssecKeyResult(DnssecKeyOutcome::FOUND, $key);
            }
        }

        return new DnssecKeyResult(DnssecKeyOutcome::NOT_FOUND);
    }
}
