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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Repository\AppSettingRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Layered reader for admin-managed settings.
 *
 * Resolution order (first non-null wins):
 *   1. app_settings row for the dotted key (admin-managed via UI - none yet)
 *   2. ConfigurationManager for the same key, split on the first "." into
 *      (group, key) so "interface.theme" -> get('interface', 'theme')
 *   3. Caller-supplied default
 *
 * Writes go straight to the repository. The service memoizes reads within
 * a single request to avoid repeating identical lookups.
 */
class AppSettingsService
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';
    public const TYPE_JSON = 'json';

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        private ConfigurationManager $config,
        private AppSettingRepositoryInterface $repository,
    ) {
    }

    public function getString(string $key, string $default = ''): string
    {
        $raw = $this->resolve($key);
        if ($raw === null) {
            return $default;
        }
        return (string)$raw;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $raw = $this->resolve($key);
        if ($raw === null) {
            return $default;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int)$raw;
        }
        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }
        return $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $raw = $this->resolve($key);
        if ($raw === null) {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        if (is_string($raw)) {
            $lower = strtolower($raw);
            if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return $default;
    }

    /**
     * @param array<int|string, mixed> $default
     * @return array<int|string, mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $raw = $this->resolve($key);
        if ($raw === null) {
            return $default;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $default;
    }

    public function setString(string $key, string $value): void
    {
        $this->writeAndInvalidate($key, $value, self::TYPE_STRING);
    }

    public function setInt(string $key, int $value): void
    {
        $this->writeAndInvalidate($key, (string)$value, self::TYPE_INT);
    }

    public function setBool(string $key, bool $value): void
    {
        $this->writeAndInvalidate($key, $value ? 'true' : 'false', self::TYPE_BOOL);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    public function setArray(string $key, array $value): void
    {
        $encoded = json_encode($value);
        if ($encoded === false) {
            return;
        }
        $this->writeAndInvalidate($key, $encoded, self::TYPE_JSON);
    }

    public function clear(string $key): void
    {
        $this->repository->delete($key);
        unset($this->cache[$key]);
    }

    /**
     * Resolves the raw value for a key: DB first (cast by stored type),
     * then ConfigurationManager (split key on first "."), else null.
     */
    private function resolve(string $key): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $row = $this->repository->find($key);
        if ($row !== null) {
            $value = $this->castFromStorage($row['value'], $row['type']);
            $this->cache[$key] = $value;
            return $value;
        }

        $configValue = $this->lookupConfig($key);
        $this->cache[$key] = $configValue;
        return $configValue;
    }

    private function lookupConfig(string $key): mixed
    {
        $dot = strpos($key, '.');
        if ($dot === false) {
            return null;
        }
        $group = substr($key, 0, $dot);
        $subKey = substr($key, $dot + 1);
        if ($group === '' || $subKey === '') {
            return null;
        }
        return $this->config->get($group, $subKey);
    }

    private function castFromStorage(string $value, string $type): mixed
    {
        return match ($type) {
            self::TYPE_INT => preg_match('/^-?\d+$/', $value) === 1 ? (int)$value : $value,
            self::TYPE_BOOL => $value === 'true' || $value === '1',
            self::TYPE_JSON => (function () use ($value) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : null;
            })(),
            default => $value,
        };
    }

    private function writeAndInvalidate(string $key, string $value, string $type): void
    {
        $this->repository->save($key, $value, $type);
        unset($this->cache[$key]);
    }
}
