<?php

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

namespace Poweradmin\Infrastructure\Repository;

use DateTime;
use Exception;
use PDO;
use Poweradmin\Domain\Model\ApiKey;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Class DbApiKeyRepository
 *
 * Database implementation of the API key repository
 *
 * @package Poweradmin\Infrastructure\Repository
 */
class DbApiKeyRepository implements ApiKeyRepositoryInterface
{
    private PDOCommon $db;
    private ConfigurationManager $config;

    /**
     * DbApiKeyRepository constructor
     *
     * @param PDOCommon $db The PDO database layer
     * @param ConfigurationManager $config The configuration manager
     */
    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
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
        // Log the query details for debugging
        error_log("[DbApiKeyRepository] Looking up API key in database");
        error_log(sprintf(
            "[DbApiKeyRepository] Key length: %d, First chars: %s, Last chars: %s",
            strlen($secretKey),
            substr($secretKey, 0, 4),
            substr($secretKey, -4)
        ));

        // Try a different approach first - direct comparison to work around database encoding issues
        try {
            $allKeys = $this->db->query("SELECT id, name, secret_key FROM api_keys");
            $found = false;
            $foundId = null;

            while ($row = $allKeys->fetch(PDO::FETCH_ASSOC)) {
                // Debug each key
                error_log(sprintf(
                    "[DbApiKeyRepository] Found key ID %d in DB, length: %d, prefix: %s, suffix: %s",
                    $row['id'],
                    strlen($row['secret_key']),
                    substr($row['secret_key'], 0, 4),
                    substr($row['secret_key'], -4)
                ));

                // Check for exact match
                if ($row['secret_key'] === $secretKey) {
                    error_log("[DbApiKeyRepository] EXACT MATCH FOUND with ID: " . $row['id']);
                    $found = true;
                    $foundId = $row['id'];
                    break;
                }
            }

            // If we found a match, get the full record
            if ($found && $foundId) {
                $exactStmt = $this->db->prepare("SELECT * FROM api_keys WHERE id = :id");
                $exactStmt->bindValue(':id', $foundId, PDO::PARAM_INT);
                $exactStmt->execute();
                $exactResult = $exactStmt->fetch(PDO::FETCH_ASSOC);

                if ($exactResult) {
                    error_log(sprintf(
                        "[DbApiKeyRepository] Successfully found key by ID: %d, Name: %s",
                        $exactResult['id'],
                        $exactResult['name']
                    ));
                    return $this->createFromArray($exactResult);
                }
            }

            // Fall back to original query
            error_log("[DbApiKeyRepository] No exact match found, trying normal query");
            $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE secret_key = :secretKey");
            $stmt->bindValue(':secretKey', $secretKey);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                // Log that no matching key was found
                error_log("[DbApiKeyRepository] No API key found matching the provided secret key");

                // For debugging only, check if keys exist at all
                $countStmt = $this->db->prepare("SELECT COUNT(*) FROM api_keys");
                $countStmt->execute();
                $count = $countStmt->fetchColumn();
                error_log("[DbApiKeyRepository] Total API keys in database: " . $count);

                return null;
            }

            // Log basic info about the found key (don't log the actual key)
            error_log(sprintf(
                "[DbApiKeyRepository] Found API key ID: %d, Name: %s, Created by: %d",
                $result['id'],
                $result['name'],
                $result['created_by']
            ));

            return $this->createFromArray($result);
        } catch (\Exception $e) {
            // Log any database errors
            error_log("[DbApiKeyRepository] Database error while finding API key: " . $e->getMessage());
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function save(ApiKey $apiKey): ApiKey
    {
        if ($apiKey->getId() === null) {
            // Insert new API key
            $stmt = $this->db->prepare("
                INSERT INTO api_keys (
                    name, 
                    secret_key, 
                    created_by, 
                    created_at, 
                    last_used_at, 
                    disabled, 
                    expires_at
                ) VALUES (
                    :name, 
                    :secretKey, 
                    :createdBy, 
                    :createdAt, 
                    :lastUsedAt, 
                    :disabled, 
                    :expiresAt
                )
            ");
        } else {
            // Update existing API key
            $stmt = $this->db->prepare("
                UPDATE api_keys SET 
                    name = :name, 
                    secret_key = :secretKey, 
                    created_by = :createdBy, 
                    created_at = :createdAt, 
                    last_used_at = :lastUsedAt,
                    disabled = :disabled, 
                    expires_at = :expiresAt
                WHERE id = :id
            ");

            $stmt->bindValue(':id', $apiKey->getId(), PDO::PARAM_INT);
        }

        $stmt->bindValue(':name', $apiKey->getName());
        $stmt->bindValue(':secretKey', $apiKey->getSecretKey());
        $stmt->bindValue(':createdBy', $apiKey->getCreatedBy(), $apiKey->getCreatedBy() !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':createdAt', $apiKey->getCreatedAt()->format('Y-m-d H:i:s'));
        $stmt->bindValue(':lastUsedAt', $apiKey->getLastUsedAt() ? $apiKey->getLastUsedAt()->format('Y-m-d H:i:s') : null);
        $stmt->bindValue(':disabled', DbCompat::boolValue($apiKey->isDisabled()));
        $stmt->bindValue(':expiresAt', $apiKey->getExpiresAt() ? $apiKey->getExpiresAt()->format('Y-m-d H:i:s') : null);

        $stmt->execute();

        if ($apiKey->getId() === null) {
            $id = $this->db->lastInsertId();
            $apiKey->setId((int) $id);
        }

        return $apiKey;
    }

    /**
     * @inheritDoc
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM api_keys WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
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
     * Create an ApiKey object from a database row array
     *
     * @param array $data The database row data
     * @return ApiKey The created ApiKey object
     */
    private function createFromArray(array $data): ApiKey
    {
        try {
            $createdAt = new DateTime($data['created_at']);
            $lastUsedAt = $data['last_used_at'] ? new DateTime($data['last_used_at']) : null;
            $expiresAt = $data['expires_at'] ? new DateTime($data['expires_at']) : null;
        } catch (Exception $e) {
            // Handle date parsing errors
            $createdAt = new DateTime();
            $lastUsedAt = null;
            $expiresAt = null;
        }

        return new ApiKey(
            $data['name'],
            $data['secret_key'],
            $data['created_by'] !== null ? (int) $data['created_by'] : null,
            $createdAt,
            $lastUsedAt,
            (bool) $data['disabled'],
            $expiresAt,
            (int) $data['id']
        );
    }
}
