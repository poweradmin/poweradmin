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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\ApiKey;

/**
 * Interface ApiKeyRepositoryInterface
 *
 * Repository interface for API key operations
 *
 * @package Poweradmin\Domain\Repository
 */
interface ApiKeyRepositoryInterface
{
    /**
     * Get all API keys
     *
     * @param int|null $userId Optional user ID to filter by creator
     * @return ApiKey[] Array of API keys
     */
    public function getAll(?int $userId = null): array;

    /**
     * Find an API key by ID
     *
     * @param int $id The ID of the API key to find
     * @return ApiKey|null The API key, or null if not found
     */
    public function findById(int $id): ?ApiKey;

    /**
     * Find an API key by its secret key value
     *
     * @param string $secretKey The secret key to find
     * @return ApiKey|null The API key, or null if not found
     */
    public function findBySecretKey(string $secretKey): ?ApiKey;

    /**
     * Save an API key
     *
     * @param ApiKey $apiKey The API key to save
     * @return ApiKey The saved API key with ID updated if it was a new key
     */
    public function save(ApiKey $apiKey): ApiKey;

    /**
     * Delete an API key
     *
     * @param int $id The ID of the API key to delete
     * @return bool True if the API key was deleted, false otherwise
     */
    public function delete(int $id): bool;

    /**
     * Update the last used timestamp of an API key
     *
     * @param int $id The ID of the API key to update
     * @return bool True if the API key was updated, false otherwise
     */
    public function updateLastUsed(int $id): bool;

    /**
     * Disable an API key
     *
     * @param int $id The ID of the API key to disable
     * @return bool True if the API key was disabled, false otherwise
     */
    public function disable(int $id): bool;

    /**
     * Enable an API key
     *
     * @param int $id The ID of the API key to enable
     * @return bool True if the API key was enabled, false otherwise
     */
    public function enable(int $id): bool;

    /**
     * Count number of API keys for a user
     *
     * @param int $userId The user ID
     * @return int Number of API keys
     */
    public function countByUser(int $userId): int;
}
