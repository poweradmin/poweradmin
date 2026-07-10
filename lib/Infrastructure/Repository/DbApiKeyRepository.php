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

namespace Poweradmin\Infrastructure\Repository;

use DateTime;
use Exception;
use PDO;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class DbApiKeyRepository
 *
 * Database implementation of the API key repository
 *
 * @package Poweradmin\Infrastructure\Repository
 */
class DbApiKeyRepository implements ApiKeyRepositoryInterface
{
    private PDO $db;
    private ConfigurationManager $config;
    private LoggerInterface $logger;

    /**
     * DbApiKeyRepository constructor
     *
     * @param PDO $db The PDO database layer
     * @param ConfigurationManager $config The configuration manager
     * @param LoggerInterface|null $logger The logger instance
     */
    public function __construct(PDO $db, ConfigurationManager $config, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @inheritDoc
     */
    public function getAll(?int $userId = null): array
    {
        $sql = "SELECT * FROM api_keys";
        $params = [];

        if ($userId !== null) {
            $sql .= " WHERE created_by = :userId";
            $params['userId'] = $userId;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);

        foreach ($params as $param => $value) {
            $stmt->bindValue(':' . $param, $value);
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'createFromArray'], $results);
    }

    /**
     * @inheritDoc
     */
    public function findById(int $id): ?ApiKey
    {
        $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return null;
        }

        return $this->createFromArray($result);
    }

    /**
     * @inheritDoc
     */
    public function findBySecretKey(string $secretKey): ?ApiKey
    {
        try {
            $hash = self::hashSecretKey($secretKey);
            $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE secret_key = :secretKey");
            $stmt->bindValue(':secretKey', $hash);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                return $this->createFromArray($result);
            }

            // Legacy plaintext keys (pre-4.5.0) are migrated to a SHA-256 hash on
            // first use. Operators upgrading from 4.4.x retain working keys; rows
            // that are never used remain plaintext until manually rotated.
            return $this->findAndMigratePlaintext($secretKey, $hash);
        } catch (\Exception $e) {
            $this->logger->error('[DbApiKeyRepository] Database error while finding API key: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * One-way hash applied to API keys before persistence. SHA-256 is sufficient
     * because keys are 256+ bits of entropy from `random_bytes(32)`; brute force
     * across the keyspace is infeasible without a salt. The `sha256$` prefix
     * makes hashed entries syntactically distinct from any legacy plaintext key
     * so the plaintext-fallback path can refuse pass-the-hash candidates without
     * also rejecting unusual legacy key formats.
     */
    public static function hashSecretKey(string $secretKey): string
    {
        return 'sha256$' . hash('sha256', $secretKey);
    }

    private const HASH_PREFIX = 'sha256$';

    private function findAndMigratePlaintext(string $plaintext, string $hash): ?ApiKey
    {
        // Refuse candidates already in our hash format (`sha256$<hex>`). Without
        // this gate, an attacker who read the hashed column from the database
        // could submit the raw stored value as a candidate: hashing it misses
        // the hashed lookup, the plaintext SELECT then matches the row byte-for-
        // byte, and the row is migrated to a fresh hash - auth bypass plus
        // lock-out of the real owner. The prefix is distinctive enough that no
        // realistic legacy plaintext key starts with it.
        if (str_starts_with($plaintext, self::HASH_PREFIX)) {
            return null;
        }

        // MySQL's default collation is case-insensitive, which would let a
        // mistyped/case-shifted candidate match the stored plaintext and then
        // migrate the row to the wrong-cased hash, locking the real owner out.
        // BINARY restores byte-exact comparison; PostgreSQL and SQLite are
        // already byte-exact by default.
        $dbType = $this->config->get('database', 'type', 'mysql');
        $matchExpr = ($dbType === 'mysql' || $dbType === 'mysqli') ? 'BINARY secret_key' : 'secret_key';

        $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE $matchExpr = :secretKey");
        $stmt->bindValue(':secretKey', $plaintext);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }

        $update = $this->db->prepare(
            "UPDATE api_keys SET secret_key = :hash WHERE id = :id AND $matchExpr = :plaintext"
        );
        $update->bindValue(':hash', $hash);
        $update->bindValue(':id', (int) $result['id'], PDO::PARAM_INT);
        $update->bindValue(':plaintext', $plaintext);
        $update->execute();

        return $this->createFromArray($result);
    }

    /**
     * @inheritDoc
     *
     * The secret key is hashed before persistence. Updates that do not include a
     * fresh secret (`getSecretKey()` empty - rows loaded via {@see createFromArray})
     * leave the stored hash untouched so non-secret edits (name, disabled, expiry)
     * cannot accidentally overwrite the key with a re-hash of an empty string.
     */
    public function save(ApiKey $apiKey): ApiKey
    {
        $hasSecret = $apiKey->getSecretKey() !== '';

        if ($apiKey->getId() === null) {
            $stmt = $this->db->prepare("
                INSERT INTO api_keys (
                    name, secret_key, created_by, created_at, last_used_at, disabled, expires_at,
                    is_readonly, allowed_operations
                ) VALUES (
                    :name, :secretKey, :createdBy, :createdAt, :lastUsedAt, :disabled, :expiresAt,
                    :isReadonly, :allowedOperations
                )
            ");
            $stmt->bindValue(':secretKey', self::hashSecretKey($apiKey->getSecretKey()));
        } elseif ($hasSecret) {
            $stmt = $this->db->prepare("
                UPDATE api_keys SET
                    name = :name, secret_key = :secretKey, created_by = :createdBy,
                    created_at = :createdAt, last_used_at = :lastUsedAt,
                    disabled = :disabled, expires_at = :expiresAt,
                    is_readonly = :isReadonly, allowed_operations = :allowedOperations
                WHERE id = :id
            ");
            $stmt->bindValue(':id', $apiKey->getId(), PDO::PARAM_INT);
            $stmt->bindValue(':secretKey', self::hashSecretKey($apiKey->getSecretKey()));
        } else {
            $stmt = $this->db->prepare("
                UPDATE api_keys SET
                    name = :name, created_by = :createdBy,
                    created_at = :createdAt, last_used_at = :lastUsedAt,
                    disabled = :disabled, expires_at = :expiresAt,
                    is_readonly = :isReadonly, allowed_operations = :allowedOperations
                WHERE id = :id
            ");
            $stmt->bindValue(':id', $apiKey->getId(), PDO::PARAM_INT);
        }

        $allowedOperations = $apiKey->getAllowedOperations();

        $stmt->bindValue(':name', $apiKey->getName());
        $stmt->bindValue(':createdBy', $apiKey->getCreatedBy(), $apiKey->getCreatedBy() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':createdAt', $apiKey->getCreatedAt()->format('Y-m-d H:i:s'));
        $stmt->bindValue(':lastUsedAt', $apiKey->getLastUsedAt() ? $apiKey->getLastUsedAt()->format('Y-m-d H:i:s') : null);
        $stmt->bindValue(':disabled', DbCompat::boolValue($apiKey->isDisabled()));
        $stmt->bindValue(':expiresAt', $apiKey->getExpiresAt() ? $apiKey->getExpiresAt()->format('Y-m-d H:i:s') : null);
        $stmt->bindValue(':isReadonly', DbCompat::boolValue($apiKey->isReadonly()));
        $stmt->bindValue(':allowedOperations', $allowedOperations === null ? null : implode(',', $allowedOperations));

        $stmt->execute();

        if ($apiKey->getId() === null) {
            $id = $this->db->lastInsertId('api_keys_id_seq');
            $apiKey->setId((int) $id);
        }

        return $apiKey;
    }

    /**
     * @inheritDoc
     */
    public function delete(int $id): bool
    {
        // Remove scope rows explicitly: the ON DELETE CASCADE is only present on
        // databases built from the SQL structure files, not on installer-created
        // schemas, so we cannot rely on it alone.
        //
        // Wrap both deletes in one transaction: deleting the scope rows first and
        // then failing to delete the key would leave a key with no scope, i.e. an
        // unrestricted key. On failure everything rolls back.
        $this->db->beginTransaction();

        try {
            $zones = $this->db->prepare("DELETE FROM api_key_zones WHERE api_key_id = :id");
            $zones->bindValue(':id', $id, PDO::PARAM_INT);
            $zones->execute();

            $stmt = $this->db->prepare("DELETE FROM api_keys WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $deleted = $stmt->rowCount() > 0;

            $this->db->commit();
            return $deleted;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function updateLastUsed(int $id): bool
    {
        $db_type = $this->config->get('database', 'type');
        $stmt = $this->db->prepare("UPDATE api_keys SET last_used_at = " . DbCompat::now($db_type) . " WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function disable(int $id): bool
    {
        $db_type = $this->config->get('database', 'type');
        $stmt = $this->db->prepare("UPDATE api_keys SET disabled = " . DbCompat::boolTrue($db_type) . " WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function enable(int $id): bool
    {
        $db_type = $this->config->get('database', 'type');
        $stmt = $this->db->prepare("UPDATE api_keys SET disabled = " . DbCompat::boolFalse($db_type) . " WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM api_keys WHERE created_by = :userId");
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Create an ApiKey object from a database row array.
     *
     * The stored secret is a SHA-256 hash; we do NOT expose it on the in-memory
     * model. Callers can verify a candidate via {@see findBySecretKey}, but the
     * raw key is only known at creation/regeneration time.
     */
    private function createFromArray(array $data): ApiKey
    {
        try {
            $createdAt = new DateTime($data['created_at']);
            $lastUsedAt = $data['last_used_at'] ? new DateTime($data['last_used_at']) : null;
            $expiresAt = $data['expires_at'] ? new DateTime($data['expires_at']) : null;
        } catch (Exception $e) {
            $createdAt = new DateTime();
            $lastUsedAt = null;
            $expiresAt = null;
        }

        $apiKey = new ApiKey(
            $data['name'],
            '',
            $data['created_by'] !== null ? (int) $data['created_by'] : null,
            $createdAt,
            $lastUsedAt,
            (bool) $data['disabled'],
            $expiresAt,
            (int) $data['id']
        );

        // Scope columns are absent on rows created before the 4.5.0 upgrade; treat
        // a missing value as "unrestricted" so legacy keys keep working. Use the DB
        // boolean normalizer: PostgreSQL returns booleans as 't'/'f' strings, and a
        // bare (bool) cast of 'f' would be true.
        $apiKey->setIsReadonly(DbCompat::boolFromDb($data['is_readonly'] ?? false) === 1);

        $operations = $data['allowed_operations'] ?? null;
        if ($operations !== null && $operations !== '') {
            $apiKey->setAllowedOperations(array_values(array_filter(array_map('trim', explode(',', $operations)))));
        }

        return $apiKey;
    }

    /**
     * @inheritDoc
     */
    public function getZoneIds(int $apiKeyId): array
    {
        $stmt = $this->db->prepare("SELECT zone_id FROM api_key_zones WHERE api_key_id = :apiKeyId ORDER BY zone_id");
        $stmt->bindValue(':apiKeyId', $apiKeyId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @inheritDoc
     */
    public function saveZoneIds(int $apiKeyId, array $zoneIds): void
    {
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare("DELETE FROM api_key_zones WHERE api_key_id = :apiKeyId");
            $delete->bindValue(':apiKeyId', $apiKeyId, PDO::PARAM_INT);
            $delete->execute();

            $unique = array_values(array_unique(array_map('intval', $zoneIds)));
            if ($unique !== []) {
                $insert = $this->db->prepare("INSERT INTO api_key_zones (api_key_id, zone_id) VALUES (:apiKeyId, :zoneId)");
                foreach ($unique as $zoneId) {
                    $insert->bindValue(':apiKeyId', $apiKeyId, PDO::PARAM_INT);
                    $insert->bindValue(':zoneId', $zoneId, PDO::PARAM_INT);
                    $insert->execute();
                }
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
