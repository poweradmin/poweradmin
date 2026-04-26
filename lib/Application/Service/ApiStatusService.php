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

namespace Poweradmin\Application\Service;

/**
 * Tracks the most recent PowerDNS API error so the UI can surface it.
 *
 * Session-backed so admins see a banner on the dashboard after a failing
 * request without having to tail log files. Cleared on the next successful
 * request.
 */
class ApiStatusService
{
    private const SESSION_KEY = 'pdns_api_last_error';

    public function recordError(string $message, array $context = []): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'message' => $message,
            'context' => $context,
            'timestamp' => time(),
        ];
    }

    public function clearError(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * @return array{message: string, context: array, timestamp: int}|null
     */
    public function getLastError(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }
}
