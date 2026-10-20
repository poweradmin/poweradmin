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

namespace TestHelpers;

use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * In-memory ConfigurationInterface backed by a plain array, for tests.
 *
 * A dotted key matches a literal key first, then walks nested arrays the way
 * ConfigurationManager does; a missing or null value yields the default.
 */
class FakeConfiguration implements ConfigurationInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        if (isset($this->config[$group][$key])) {
            return $this->config[$group][$key];
        }

        $value = $this->config[$group] ?? null;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !isset($value[$part])) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function getGroup(string $group): array
    {
        return $this->config[$group] ?? [];
    }

    public function getAll(): array
    {
        return $this->config;
    }
}
