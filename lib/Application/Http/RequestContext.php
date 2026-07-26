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

namespace Poweradmin\Application\Http;

/**
 * Static helpers classifying the current request from server globals:
 * API route detection and JSON response negotiation.
 *
 * All detection keys off the real routed path, never requestData['page'],
 * so a spoofed ?page=api/... query cannot flip the result.
 */
final class RequestContext
{
    /**
     * Path portion of the current request, with the query string stripped.
     */
    public static function path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    }

    /**
     * Checks if the current request is any API route
     *
     * @return bool True if this is an API request, false otherwise
     */
    public static function isApiRequest(): bool
    {
        return preg_match('#/api/(internal|v\d+)(/|$)#', self::path()) === 1;
    }

    /**
     * Checks if the current request is an internal API route (api/internal/*)
     *
     * @return bool True if this is an internal API route, false otherwise
     */
    public static function isInternalApiRoute(): bool
    {
        return preg_match('#/api/internal(/|$)#', self::path()) === 1;
    }

    /**
     * Whether the current request targets the v2 public API. Matches on the URL path
     * only (query string ignored) so the v2 error-wrapper decision is not swayed by an
     * unrelated URL that merely mentions /api/v2/ in its query string.
     */
    public static function isV2ApiRequest(): bool
    {
        return preg_match('#/api/v2(/|$)#', self::path()) === 1;
    }

    /**
     * Checks if the current request expects a JSON response
     * This is more comprehensive than just checking the route
     *
     * @return bool True if this request expects JSON, false otherwise
     */
    public static function expectsJson(): bool
    {
        // Check if it's an API route
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($requestUri, '/api/')) {
            return true;
        }

        // Check Accept header
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($acceptHeader, 'application/json') && !str_contains($acceptHeader, 'text/html')) {
            return true;
        }

        // Check if it's an AJAX request
        if (
            isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            return true;
        }

        // Check Content-Type for JSON requests
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        return false;
    }
}
