<?php

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\UserPreference;

interface UserPreferenceRepositoryInterface
{
    public function findByUserIdAndKey(int $userId, string $key): ?UserPreference;

    public function findAllByUserId(int $userId): array;

    public function save(UserPreference $preference): void;

    public function createOrUpdate(int $userId, string $key, ?string $value): void;

    public function deleteByUserIdAndKey(int $userId, string $key): void;

    public function deleteAllByUserId(int $userId): void;
}
