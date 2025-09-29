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

namespace Poweradmin\Domain\Service;

use InvalidArgumentException;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Repository\UserPreferenceRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class UserPreferenceService
{
    private UserPreferenceRepositoryInterface $repository;
    private ConfigurationInterface $config;
    private array $cache = [];
    private array $defaultValues = [];

    public function __construct(
        UserPreferenceRepositoryInterface $repository,
        ConfigurationInterface $config
    ) {
        $this->repository = $repository;
        $this->config = $config;
        $this->initializeDefaults();
    }

    public function getPreference(int $userId, string $key): ?string
    {
        $cacheKey = $userId . '_' . $key;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $preference = $this->repository->findByUserIdAndKey($userId, $key);

        if ($preference === null) {
            $value = $this->getDefaultValue($key);
        } else {
            $value = $preference->getPreferenceValue();
        }

        $this->cache[$cacheKey] = $value;

        return $value;
    }

    public function setPreference(int $userId, string $key, ?string $value): void
    {
        if (!UserPreference::isValidKey($key)) {
            throw new InvalidArgumentException("Invalid preference key: {$key}");
        }

        $this->repository->createOrUpdate($userId, $key, $value);

        $cacheKey = $userId . '_' . $key;
        $this->cache[$cacheKey] = $value;
    }

    public function getAllPreferences(int $userId): array
    {
        $preferences = $this->repository->findAllByUserId($userId);
        $result = [];

        foreach ($this->defaultValues as $key => $defaultValue) {
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
            throw new InvalidArgumentException("Rows per page must be between 5 and 500");
        }

        $this->setPreference($userId, UserPreference::KEY_ROWS_PER_PAGE, (string)$rows);
    }



    public function getShowZoneSerial(int $userId): bool
    {
        return $this->getPreference($userId, UserPreference::KEY_SHOW_ZONE_SERIAL) === 'true';
    }

    public function getShowZoneTemplate(int $userId): bool
    {
        return $this->getPreference($userId, UserPreference::KEY_SHOW_ZONE_TEMPLATE) === 'true';
    }

    public function getRecordFormPosition(int $userId): string
    {
        return $this->getPreference($userId, UserPreference::KEY_RECORD_FORM_POSITION) ?: 'top';
    }

    public function getSaveButtonPosition(int $userId): string
    {
        return $this->getPreference($userId, UserPreference::KEY_SAVE_BUTTON_POSITION) ?: 'bottom';
    }


    public function clearCache(): void
    {
        $this->cache = [];
    }

    private function initializeDefaults(): void
    {
        $this->defaultValues = [
            UserPreference::KEY_ROWS_PER_PAGE => (string)$this->config->get('interface', 'rows_per_page', 10),
            UserPreference::KEY_DEFAULT_TTL => (string)$this->config->get('dns', 'ttl', 86400),
            UserPreference::KEY_SHOW_ZONE_SERIAL => $this->config->get('interface', 'display_serial_in_zone_list', false) ? 'true' : 'false',
            UserPreference::KEY_SHOW_ZONE_TEMPLATE => $this->config->get('interface', 'display_template_in_zone_list', false) ? 'true' : 'false',
            UserPreference::KEY_RECORD_FORM_POSITION => $this->config->get('interface', 'position_record_form_top', true) ? 'top' : 'bottom',
            UserPreference::KEY_SAVE_BUTTON_POSITION => $this->config->get('interface', 'position_save_button_top', false) ? 'top' : 'bottom',
            UserPreference::KEY_DEFAULT_ZONE_VIEW => 'standard',
            UserPreference::KEY_ZONE_SORT_ORDER => 'asc',
        ];
    }

    private function getDefaultValue(string $key): ?string
    {
        return $this->defaultValues[$key] ?? null;
    }
}
