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
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Class DbApiKeyRepository
 *
 * Database implementation of the API key repository
 *
 * @package Poweradmin\Infrastructure\Repository
 */
class DbApiKeyRepository implements ApiKeyRepositoryInterface
{
    private PDOLayer $db;
    private ConfigurationManager $config;

    /**
     * DbApiKeyRepository constructor
     *
     * @param PDOLayer $db The PDO database layer
     * @param ConfigurationManager $config The configuration manager
     */
    public function __construct(PDOLayer $db, ConfigurationManager $config)
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
        $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE secret_key = :secretKey");
        $stmt->bindValue(':secretKey', $secretKey);
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
        $stmt->bindValue(':disabled', $apiKey->isDisabled(), PDO::PARAM_BOOL);
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
        $stmt = $this->db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function disable(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE api_keys SET disabled = TRUE WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * @inheritDoc
     */
    public function enable(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE api_keys SET disabled = FALSE WHERE id = :id");
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
