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

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Priority: configured timezone > php.ini date.timezone > UTC
 */
function initializeTimezone(ConfigurationInterface $config): void
{
    $timezone = $config->get('misc', 'timezone');

    if ($timezone) {
        date_default_timezone_set($timezone);
    } elseif (!ini_get('date.timezone')) {
        date_default_timezone_set('UTC');
    }
}

/**
 * Initialize secure session configuration
 */
function initializeSession(ConfigurationInterface $config): void
{
    if (!function_exists('session_start')) {
        require_once __DIR__ . '/../../Infrastructure/Service/MessageService.php';
        (new MessageService())->displayDirectSystemError("You have to install the PHP session extension!");
    }

    // PHP collects sessions after gc_maxlifetime, 1440s by default, which is shorter
    // than the 1800s timeout shipped in interface.session_timeout. The session then
    // vanished before the expiry check could report it and the user was returned to a
    // login page with no explanation. Keep collection strictly behind our own timeout.
    $sessionTimeout = (int)$config->get('interface', 'session_timeout', 1800);
    if ($sessionTimeout > 0) {
        ini_set('session.gc_maxlifetime', (string)($sessionTimeout + 300));
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
