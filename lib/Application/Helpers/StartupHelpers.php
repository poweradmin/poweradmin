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

use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Initialize secure session configuration
 */
function initializeSession(): void
{
    if (!function_exists('session_start')) {
        require_once __DIR__ . '/../../Infrastructure/Service/MessageService.php';
        (new MessageService())->displayDirectSystemError("You have to install the PHP session extension!");
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'secure' => $secure,
        'httponly' => true,
    ]);

    session_start();
}

/**
 * Send JSON error response
 */
function sendJsonError(string $message, ?string $file = null, ?int $line = null, ?array $trace = null): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'trace' => $trace
    ]);
}
