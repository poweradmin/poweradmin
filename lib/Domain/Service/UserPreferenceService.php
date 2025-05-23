<?php

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Repository\UserPreferenceRepositoryInterface;

class UserPreferenceService
{
    private UserPreferenceRepositoryInterface $repository;
    private array $cache = [];

    public function __construct(UserPreferenceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function getPreference(int $userId, string $key): ?string
    {
        $cacheKey = $userId . '_' . $key;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $preference = $this->repository->findByUserIdAndKey($userId, $key);

        if ($preference === null) {
            $value = UserPreference::getDefaultValue($key);
        } else {
            $value = $preference->getPreferenceValue();
        }

        $this->cache[$cacheKey] = $value;

        return $value;
    }

    public function setPreference(int $userId, string $key, ?string $value): void
    {
        if (!UserPreference::isValidKey($key)) {
            throw new \InvalidArgumentException("Invalid preference key: {$key}");
        }

        $this->repository->createOrUpdate($userId, $key, $value);

        $cacheKey = $userId . '_' . $key;
        $this->cache[$cacheKey] = $value;
    }

    public function getAllPreferences(int $userId): array
    {
        $preferences = $this->repository->findAllByUserId($userId);
        $result = [];

        foreach (UserPreference::DEFAULT_VALUES as $key => $defaultValue) {
            $result[$key] = $defaultValue;
        }

        foreach ($preferences as $preference) {
            $result[$preference->getPreferenceKey()] = $preference->getPreferenceValue();
        }

        return $result;
    }

    public function resetPreference(int $userId, string $key): void
    {
        $this->repository->deleteByUserIdAndKey($userId, $key);

        $cacheKey = $userId . '_' . $key;
        unset($this->cache[$cacheKey]);
    }

    public function resetAllPreferences(int $userId): void
    {
        $this->repository->deleteAllByUserId($userId);

        foreach (array_keys($this->cache) as $cacheKey) {
            if (str_starts_with($cacheKey, $userId . '_')) {
                unset($this->cache[$cacheKey]);
            }
        }
    }

    public function getRowsPerPage(int $userId): int
    {
        $value = $this->getPreference($userId, UserPreference::KEY_ROWS_PER_PAGE);
        return (int)($value ?: 10);
    }

    public function setRowsPerPage(int $userId, int $rows): void
    {
        if ($rows < 5 || $rows > 500) {
            throw new \InvalidArgumentException("Rows per page must be between 5 and 500");
        }

        $this->setPreference($userId, UserPreference::KEY_ROWS_PER_PAGE, (string)$rows);
    }

    public function getUiTheme(int $userId): string
    {
        return $this->getPreference($userId, UserPreference::KEY_UI_THEME) ?: 'light';
    }

    public function setUiTheme(int $userId, string $theme): void
    {
        if (!in_array($theme, ['light', 'dark'], true)) {
            throw new \InvalidArgumentException("Invalid theme: {$theme}");
        }

        $this->setPreference($userId, UserPreference::KEY_UI_THEME, $theme);
    }

    public function getLanguage(int $userId): string
    {
        return $this->getPreference($userId, UserPreference::KEY_LANGUAGE) ?: 'en_EN';
    }

    public function setLanguage(int $userId, string $language): void
    {
        $this->setPreference($userId, UserPreference::KEY_LANGUAGE, $language);
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
