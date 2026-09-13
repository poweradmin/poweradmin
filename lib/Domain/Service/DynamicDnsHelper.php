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

/**
 * Helper functions for dynamic DNS updates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DynamicDnsHelper
{

    /**
     * Get exit status message
     *
     * Print verbose status message for request
     *
     * @param string $status Short status message
     * @param bool $verbose Print the long explanation instead of the short code
     *
     * @return boolean false
     */
    public static function statusExit(string $status, bool $verbose = false): bool
    {
        $verbose_codes = array(
            'badagent' => 'Your user agent is not valid.',
            'badauth' => 'Invalid username or password.  Authentication failed.',
            'notfqdn' => 'The hostname you specified was not valid.',
            'dnserr' => 'A DNS error has occurred on our end.  We apologize for any inconvenience.',
            '!yours' => 'The specified hostname does not belong to you.',
            'nohost' => 'The specified hostname does not exist.',
            'good' => 'Your hostname has been updated.',
            '911' => 'A critical error has occurred on our end.  We apologize for any inconvenience.',
            'nochg' => 'This update was identical to your last update, so no changes were made to your hostname configuration.',
            'baddbtype' => 'Unsupported database type',
        );

        if ($verbose) {
            $pieces = preg_split('/\s/', $status);
            $status = $verbose_codes[$pieces[0]];
        }
        echo "$status\n";
        return false;
    }
}
