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

namespace Poweradmin\Infrastructure\Utility;

/**
 * DependencyCheck class verifies the availability of required PHP extensions.
 */
class DependencyCheck
{
    /**
     * Associative array of required PHP extensions and their representative functions.
     */
    const DEPENDENCIES = array(
        'intl' => 'idn_to_utf8',
        'gettext' => 'gettext',
        'openssl' => 'openssl_encrypt',
        'session' => 'session_start',
        'tokenizer' => 'token_get_all',
        'filter' => 'filter_var',
    );

    /**
     * Verifies that required PHP extensions are installed.
     *
     * If any required extension is missing, the script will be terminated with an error message.
     *
     * @return void
     */
    public static function verifyExtensions(): void
    {
        foreach (array_keys(self::DEPENDENCIES) as $extension) {
            if (!function_exists(self::DEPENDENCIES[$extension])) {
                die("You have to install PHP $extension extension!");
            }
        }
    }
}
