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

/**
 * ZoneType class represents the different types of zones in a DNS system.
 */
class ZoneType
{
    // Visibility constants for the available zone types
    public const MASTER = "MASTER";
    public const SLAVE = "SLAVE";
    public const NATIVE = "NATIVE";

    /**
     * Get an array of the available zone types.
     *
     * @return array The array of available zone types.
     */
    public static function getTypes(): array
    {
        return [
            self::MASTER,
            self::SLAVE,
            self::NATIVE,
        ];
    }
}
