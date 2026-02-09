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

namespace Poweradmin\Domain\Model;

use RuntimeException;

/**
 * Class for validating and working with Top Level Domains
 *
 * @package Poweradmin\Domain\Model
 */
class TopLevelDomain
{
    private const DATA_FILE = __DIR__ . '/../../../data/tlds.php';

    private static ?array $validTopLevelDomains = null;

    private function __construct()
    {
    }

    /**
     * Load TLD data from external file
     *
     * @return array{tlds: string[], special: string[]}
     * @throws RuntimeException if data file cannot be loaded
     */
    private static function loadData(): array
    {
        if (!file_exists(self::DATA_FILE)) {
            throw new RuntimeException('TLD data file not found: ' . self::DATA_FILE);
        }

        $data = include self::DATA_FILE;

        if (!is_array($data) || !isset($data['tlds'], $data['special'])) {
            throw new RuntimeException('Invalid TLD data file format');
        }

        return $data;
    }

    public static function init(): void
    {
        if (self::$validTopLevelDomains !== null) {
            return;
        }

        $data = self::loadData();
        self::$validTopLevelDomains = array_merge($data['tlds'], $data['special']);
    }

    public static function isValidTopLevelDomain($hostname): bool
    {
        if (self::$validTopLevelDomains === null) {
            self::init();
        }

        $hostname_labels = explode('.', $hostname);
        $label_count = count($hostname_labels);
        $domain = strtolower($hostname_labels[$label_count - 1]);
        return in_array($domain, self::$validTopLevelDomains);
    }

    /**
     * Reset the cache (useful for testing)
     */
    public static function resetCache(): void
    {
        self::$validTopLevelDomains = null;
    }
}
