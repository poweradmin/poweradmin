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

namespace Poweradmin\Infrastructure\Configuration;

interface ConfigurationInterface
{
    /**
     * Get a configuration value
     *
     * @param string $group Configuration group
     * @param string $key Configuration key
     * @return mixed Configuration value or null if not found
     */
    public function get(string $group, string $key): mixed;
    
    /**
     * Get an entire configuration group
     *
     * @param string $group Configuration group
     * @return array Configuration group values
     */
    public function getGroup(string $group): array;
    
    /**
     * Get all configuration settings
     *
     * @return array All settings
     */
    public function getAll(): array;
}
