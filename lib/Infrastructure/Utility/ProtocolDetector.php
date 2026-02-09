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

namespace Poweradmin\Infrastructure\Utility;

/**
 * Detects the HTTP protocol (http/https) from server environment.
 *
 * Supports detection via:
 * - Direct HTTPS connection ($_SERVER['HTTPS'])
 * - Reverse proxy header (X-Forwarded-Proto)
 * - Standard HTTPS port (443)
 */
class ProtocolDetector
{
    private array $server;

    public function __construct(?array $server = null)
    {
        $this->server = $server ?? $_SERVER;
    }

    /**
     * Detect the protocol (http or https).
     *
     * @return string 'https' or 'http'
     */
    public function detect(): string
    {
        // Check HTTPS server variable
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return 'https';
        }

        // Check X-Forwarded-Proto header (for reverse proxies)
        if (!empty($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return 'https';
        }

        // Check if running on standard HTTPS port
        if (!empty($this->server['SERVER_PORT']) && $this->server['SERVER_PORT'] == '443') {
            return 'https';
        }

        return 'http';
    }

    /**
     * Check if the connection is secure (HTTPS).
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        return $this->detect() === 'https';
    }
}
