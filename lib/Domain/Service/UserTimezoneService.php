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

use PDO;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserPreferenceRepository;

class UserTimezoneService
{
    private UserPreferenceService $preferenceService;
    private ConfigurationInterface $config;
    private array $cache = [];

    public function __construct(
        UserPreferenceService $preferenceService,
        ConfigurationInterface $config
    ) {
        $this->preferenceService = $preferenceService;
        $this->config = $config;
    }

    public function getEffectiveTimezone(?int $userId): string
    {
        $cacheKey = $userId ?? 0;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $timezone = null;

        if ($userId !== null) {
            $stored = $this->preferenceService->getPreference($userId, UserPreference::KEY_TIMEZONE);
            if ($stored !== null && UserPreference::isAcceptableTimezone($stored)) {
                $timezone = $stored;
            }
        }

        if ($timezone === null) {
            $global = $this->config->get('misc', 'timezone', null);
            if (is_string($global) && UserPreference::isAcceptableTimezone($global)) {
                $timezone = $global;
            }
        }

        if ($timezone === null) {
            $timezone = 'UTC';
        }

        $this->cache[$cacheKey] = $timezone;
        return $timezone;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Build a UserTimezoneService wired to the standard DB-backed
     * preference repository. Shortcut for the many call sites that have
     * a PDO connection and config available but no service container.
     */
    public static function createDefault(PDO $db, ConfigurationManager $config): self
    {
        $dbType = $config->get('database', 'type') ?? 'mysql';
        $repository = new DbUserPreferenceRepository($db, $dbType);
        $preferenceService = new UserPreferenceService($repository, $config);
        return new self($preferenceService, $config);
    }
}
