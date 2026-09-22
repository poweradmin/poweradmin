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

namespace Poweradmin\Application;

use Closure;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\System\NotFoundController;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Process-level setup Kernel::boot() runs before routing: timezone and session cookie.
 */
class Bootstrap
{
    /**
     * The HTML 404 body BootstrapErrorResponder renders; deferred so the controller is only built on a miss.
     */
    public static function notFoundRenderer(): Closure
    {
        return static function (): void {
            try {
                (new NotFoundController([]))->run();
            } catch (RequestHalted) {
                // The 404 page is out; nothing may follow it
            }
        };
    }

    /**
     * Priority: configured timezone > php.ini date.timezone > UTC
     */
    public static function initializeTimezone(ConfigurationInterface $config): void
    {
        $timezone = $config->get('misc', 'timezone');

        if ($timezone) {
            date_default_timezone_set($timezone);
        } elseif (!ini_get('date.timezone')) {
            date_default_timezone_set('UTC');
        }
    }

    /**
     * Starts the session with secure cookie flags and a gc lifetime behind the app's own timeout.
     */
    public static function initializeSession(ConfigurationInterface $config): void
    {
        if (!function_exists('session_start')) {
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

        // session.auto_start or an embedding script may have opened the session already
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}
